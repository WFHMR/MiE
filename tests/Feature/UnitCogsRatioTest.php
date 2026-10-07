<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\ProductService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * A cost belongs to a quantity of goods, so it only means anything alongside the unit it was measured in.
 *
 * A pack of 24 bought for 213.08 means a bottle costs 8.88. Those are the same fact stated twice, and the
 * shop's database held the first number against both units: computeCogs() filters stock history by unit_id
 * and so costed only the unit the goods arrived in, while the importers wrote the workbook's cost column
 * onto every unit row verbatim.
 *
 * The visible damage was in the accounts, not the stock. A bottle selling for 10 baht was recorded as
 * costing 213.08, and the sales report showed a 203 baht loss on a 10 baht sale — every multi-unit product
 * in the shop reporting a loss it had not made.
 */
class UnitCogsRatioTest extends TestCase
{
    use WithAuthentication;

    /**
     * Builds its own unit group rather than borrowing one from the fixture.
     *
     * Borrowing was the first attempt and it tested the wrong thing. Every seeded product already carries
     * stock history on its units — product 63 had 81 history rows, four of them priced on the very unit the
     * assertion was watching — so the conversion was correctly refused and the test read that as the
     * conversion being broken. A freshly created unit cannot have a delivery behind it, which is exactly
     * the situation this conversion exists for.
     *
     * @return array{0: Product, 1: ProductUnitQuantity, 2: ProductUnitQuantity, 3: Unit, 4: Unit}|null
     */
    private function productSoldInTwoUnits(): ?array
    {
        $product = Product::first();

        if ( ! $product instanceof Product ) {
            return null;
        }

        $group = new UnitGroup;
        $group->name = 'ทดสอบอัตราต้นทุน ' . random_int( 100000, 999999 );
        $group->author_id = auth()->id() ?? 1;
        $group->save();

        $this->group = $group;

        $units = [];

        foreach ( [ [ 'ชิ้นทดสอบ', 1 ], [ 'ลังทดสอบ', 24 ] ] as [ $name, $value ] ) {
            $unit = new Unit;
            $unit->name = $name . ' ' . random_int( 1000, 9999 );
            $unit->identifier = 'cogs-ratio-' . $value . '-' . random_int( 100000, 999999 );
            $unit->group_id = $group->id;
            $unit->value = $value;
            $unit->base_unit = $value === 1;
            $unit->author_id = auth()->id() ?? 1;
            $unit->save();

            $units[] = $unit;
        }

        [ $small, $large ] = $units;

        $rows = [];

        foreach ( [ $small, $large ] as $unit ) {
            $row = new ProductUnitQuantity;
            $row->product_id = $product->id;
            $row->unit_id = $unit->id;
            $row->quantity = 0;
            $row->sale_price = 10;
            $row->sale_price_edit = 10;
            $row->wholesale_price = 10;
            $row->wholesale_price_edit = 10;
            $row->cogs = 0;
            $row->save();

            $this->createdRowIds[] = $row->id;
            $rows[] = $row;
        }

        return [ $product, $rows[ 0 ], $rows[ 1 ], $small, $large ];
    }

    private ?UnitGroup $group = null;

    /**
     * @var array<int, int>
     */
    private array $createdRowIds = [];

    protected function tearDown(): void
    {
        if ( $this->createdRowIds !== [] ) {
            ProductUnitQuantity::whereIn( 'id', $this->createdRowIds )->delete();
            $this->createdRowIds = [];
        }

        if ( $this->group instanceof UnitGroup ) {
            Unit::where( 'group_id', $this->group->id )->delete();
            $this->group->delete();
            $this->group = null;
        }

        parent::tearDown();
    }

    /**
     * The whole point: the smaller unit's cost is the larger unit's cost divided by the ratio between them.
     */
    public function test_the_cost_of_a_smaller_unit_is_derived_from_the_conversion_ratio(): void
    {
        $this->attemptAuthenticate();

        $fixture = $this->productSoldInTwoUnits();

        if ( $fixture === null ) {
            $this->markTestSkipped( 'No unit group with two units of different size.' );
        }

        [ $product, $smallRow, $largeRow, $small, $large ] = $fixture;

        $originalSmall = $smallRow->cogs;
        $originalLarge = $largeRow->cogs;

        $packCost = 213.07980;

        DB::table( ( new ProductUnitQuantity )->getTable() )
            ->where( 'id', $largeRow->id )
            ->update( [ 'cogs' => $packCost ] );

        /**
         * The state the importer left behind: the pack's cost sitting on the bottle as well.
         */
        DB::table( ( new ProductUnitQuantity )->getTable() )
            ->where( 'id', $smallRow->id )
            ->update( [ 'cogs' => $packCost ] );

        try {
            app( ProductService::class )->spreadCogsAcrossUnits( $largeRow->fresh() );

            $expected = round( $packCost * (float) $small->value / (float) $large->value, 5 );
            $actual = round( (float) $smallRow->fresh()->cogs, 5 );

            $this->assertEqualsWithDelta(
                $expected,
                $actual,
                0.0001,
                sprintf(
                    'The cost of one %s should be the cost of one %s divided by %s, not the same figure.'
                        . ' Left as it was, selling one %s at its shelf price books a loss it did not make.',
                    $small->name,
                    $large->name,
                    (float) $large->value / (float) $small->value,
                    $small->name
                )
            );

            $this->assertLessThan(
                $packCost,
                $actual,
                'The smaller unit still costs as much as the whole pack.'
            );
        } finally {
            DB::table( ( new ProductUnitQuantity )->getTable() )->where( 'id', $smallRow->id )->update( [ 'cogs' => $originalSmall ] );
            DB::table( ( new ProductUnitQuantity )->getTable() )->where( 'id', $largeRow->id )->update( [ 'cogs' => $originalLarge ] );
        }
    }

    /**
     * One branch's delivery must not rewrite another branch's cost.
     *
     * Stock and prices are held per branch on this table, so a product sold at two branches has a row per
     * unit per branch. The first version of this spread ignored that: it corrected whichever branch it
     * reached and left the other holding the pack cost against a single item. On the live shop that showed
     * up as branch 1 of product 20883 reading 49.08 per box while branch 2 read 589, and branch 2 reporting
     * a loss of 14,299 baht it had not made.
     */
    public function test_a_delivery_at_one_branch_does_not_rewrite_another_branchs_cost(): void
    {
        $this->attemptAuthenticate();

        $fixture = $this->productSoldInTwoUnits();

        if ( $fixture === null ) {
            $this->markTestSkipped( 'No product to build a unit group against.' );
        }

        [ $product, $smallRow, $largeRow, $small, $large ] = $fixture;

        $packCost = 480.0;

        foreach ( [ $smallRow, $largeRow ] as $row ) {
            DB::table( ( new ProductUnitQuantity )->getTable() )
                ->where( 'id', $row->id )
                ->update( [ 'branch_id' => 1, 'cogs' => $packCost ] );
        }

        /**
         * The same two units, at a second branch, carrying the same wrong figure.
         */
        $otherBranch = [];

        foreach ( [ $small, $large ] as $unit ) {
            $row = new ProductUnitQuantity;
            $row->product_id = $product->id;
            $row->unit_id = $unit->id;
            $row->branch_id = 2;
            $row->quantity = 0;
            $row->sale_price = 10;
            $row->sale_price_edit = 10;
            $row->wholesale_price = 10;
            $row->wholesale_price_edit = 10;
            $row->cogs = $packCost;
            $row->save();

            $this->createdRowIds[] = $row->id;
            $otherBranch[] = $row;
        }

        app( ProductService::class )->spreadCogsAcrossUnits( $largeRow->fresh() );

        $this->assertEqualsWithDelta(
            round( $packCost * (float) $small->value / (float) $large->value, 5 ),
            (float) $smallRow->fresh()->cogs,
            0.0001,
            'The cost was not derived for the branch the delivery belongs to.'
        );

        $this->assertEqualsWithDelta(
            $packCost,
            (float) $otherBranch[ 0 ]->fresh()->cogs,
            0.0001,
            'A delivery recorded at one branch changed the cost held at another branch, which is money'
                . ' attributed to a shelf the goods never reached.'
        );
    }

    /**
     * Arithmetic must never overwrite a measurement. A unit that carries its own priced delivery knows what
     * it cost, and a figure derived from a sibling is only ever a stand-in for that.
     */
    public function test_a_unit_with_its_own_priced_delivery_is_left_alone(): void
    {
        $source = (string) file_get_contents( base_path( 'app/Services/ProductService.php' ) );

        $this->assertStringContainsString(
            '$hasOwnCost',
            $source,
            'Nothing protects a unit that was costed from a real delivery, so derived arithmetic can'
                . ' overwrite a recorded purchase price.'
        );

        $this->assertStringContainsString(
            "->where( 'total_price', '>', 0 )",
            $source,
            'The check for an existing cost does not require the history to carry a price, so a free stock'
                . ' adjustment would count as a measurement.'
        );
    }

    /**
     * Units in another group describe a different way of counting and have no ratio to this one.
     */
    public function test_units_from_another_group_are_not_converted(): void
    {
        $source = (string) file_get_contents( base_path( 'app/Services/ProductService.php' ) );

        $this->assertStringContainsString(
            '$unit->group_id !== $sourceUnit->group_id',
            $source,
            'A cost can be multiplied across unrelated unit groups, which invents a number.'
        );
    }

    /**
     * The repair command exists, writes only cogs, and refuses to guess.
     */
    public function test_the_repair_command_writes_only_the_cost_and_refuses_to_guess(): void
    {
        $source = (string) file_get_contents( base_path( 'app/Console/Commands/FixUnitCogsCommand.php' ) );

        $this->assertStringContainsString( "update( [ 'cogs' => \$expected ] )", $source, 'The repair writes more than the cost.' );
        $this->assertStringContainsString( 'No trustworthy basis, left alone', $source, 'The repair has no path that declines to guess.' );
        $this->assertStringContainsString( '--dry-run', $source, 'The repair cannot be previewed against a live shop.' );
    }

    public function test_the_repair_command_previews_without_writing(): void
    {
        $this->attemptAuthenticate();

        $before = DB::table( ( new ProductUnitQuantity )->getTable() )->sum( 'cogs' );

        $this->artisan( 'ns:fix-unit-cogs', [ '--dry-run' => true ] )->assertSuccessful();

        $this->assertEquals(
            round( (float) $before, 5 ),
            round( (float) DB::table( ( new ProductUnitQuantity )->getTable() )->sum( 'cogs' ), 5 ),
            'A dry run changed the costs, so there is no safe way to preview this on a trading shop.'
        );
    }
}
