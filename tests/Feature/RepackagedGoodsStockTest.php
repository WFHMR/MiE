<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Goods the shop breaks open and weighs out itself.
 *
 * A sack of sugar holds ten kilos. The shop sells it loose at a hundred grams, two hundred, half a
 * kilo and a kilo, and every one of those sales comes out of the same sack. Keeping a separate
 * figure per size would let the same ten kilos be sold four times over, once through each label.
 *
 * This asks the stock engine the shopkeeper's question directly, with his own numbers, before any
 * of it is offered to him through a file: taking a kilo off the shelf has to leave nine, and taking
 * a hundred grams has to leave the other sizes able to see that it has gone.
 *
 * The product is built here rather than imported, so that a failure points at the deduction rather
 * than at a spreadsheet layout.
 */
class RepackagedGoodsStockTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    /**
     * A hundred grams is the smallest size sold, so it is the unit the sack is counted in: ten
     * kilos is a hundred of them. Every other size is a whole number of those.
     *
     * @var array<string, float>
     */
    private const SIZES = [
        '100 กรัม' => 1.0,
        '200 กรัม' => 2.0,
        'ครึ่งโล' => 5.0,
        '1 กก.' => 10.0,
        'กระสอบ 10 กก.' => 100.0,
    ];

    private function branchId(): int
    {
        $branch = Branch::first();

        if ( ! $branch instanceof Branch ) {
            $branch = new Branch;
            $branch->name = 'สาขาทดสอบแบ่งบรรจุ';
            $branch->code = 'RPK' . random_int( 100, 999 );
            $branch->is_main = false;
            $branch->author_id = 1;
            $branch->save();
        }

        return (int) $branch->id;
    }

    /**
     * @param  array<string, float>                                                                          $sizes  unit name to how many base units it holds
     * @param  array<string, float>                                                                          $prices unit name to selling price
     * @return array{product: Product, units: array<string, Unit>, rows: array<string, ProductUnitQuantity>}
     */
    private function buildRepackagedProduct( string $name, array $sizes, array $prices, float $baseStock ): array
    {
        $branchId = $this->branchId();

        $group = new UnitGroup;
        $group->name = $name . ' ' . random_int( 1000, 9999 );
        $group->author_id = 1;
        $group->save();

        $units = [];
        $smallest = min( $sizes );

        foreach ( $sizes as $unitName => $value ) {
            $unit = new Unit;
            $unit->name = $unitName;
            $unit->identifier = 'rpk-' . $group->id . '-' . substr( md5( $unitName ), 0, 10 );
            $unit->group_id = $group->id;
            $unit->value = $value;
            $unit->base_unit = abs( $value - $smallest ) < 0.00001;
            $unit->author_id = 1;
            $unit->save();

            $units[ $unitName ] = $unit;
        }

        $product = new Product;
        $product->name = $name;
        $product->sku = 'RPK-' . random_int( 100000, 999999 );
        $product->barcode = (string) random_int( 8800000000000, 8899999999999 );
        $product->barcode_type = 'code128';
        $product->unit_group = $group->id;
        $product->product_type = 'product';
        $product->type = Product::TYPE_MATERIALIZED;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->accurate_tracking = false;
        $product->auto_cogs = true;
        $product->searchable = true;
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->status = Product::STATUS_AVAILABLE;
        $product->author_id = 1;

        /** Without this every size keeps its own figure and the sack can be sold four times over. */
        $product->stock_on_base_unit = true;
        $product->save();

        $rows = [];

        foreach ( $units as $unitName => $unit ) {
            $row = new ProductUnitQuantity;
            $row->product_id = $product->id;
            $row->unit_id = $unit->id;
            $row->branch_id = $branchId;
            $row->type = 'product';
            $row->visible = true;
            $row->is_weighable = false;
            $row->low_quantity = 0;
            $row->stock_alert_enabled = false;

            /** Stock sits only on the size the shop counts in. The rest are price labels. */
            $row->quantity = (bool) $unit->base_unit ? $baseStock : 0.0;

            $price = (float) ( $prices[ $unitName ] ?? 0.0 );
            $row->sale_price = $price;
            $row->sale_price_edit = $price;
            $row->sale_price_gross = $price;
            $row->sale_price_net = $price;
            $row->sale_price_tax = 0;
            $row->wholesale_price = $price;
            $row->wholesale_price_edit = $price;
            $row->wholesale_price_gross = $price;
            $row->wholesale_price_net = $price;
            $row->wholesale_price_tax = 0;
            $row->cogs = 0;
            $row->save();

            $rows[ $unitName ] = $row;
        }

        return [ 'product' => $product, 'units' => $units, 'rows' => $rows ];
    }

    private function sell( ProductService $service, Product $product, Unit $unit, float $quantity, int $branchId ): void
    {
        $service->stockAdjustment( ProductHistory::ACTION_LOST, [
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'quantity' => $quantity,
            'unit_price' => 0,
            'branch_id' => $branchId,
            'author' => 1,
        ] );
    }

    /**
     * The shopkeeper's own arithmetic. One sack, then a kilo, a half kilo, two hundred grams and a
     * hundred grams sold off it. Eighteen hundred grams have left the sack, so eight point two
     * kilos remain, and the figure is held in one place throughout.
     */
    public function test_one_sack_sold_off_in_four_different_sizes_draws_down_a_single_figure(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        [ 'product' => $product, 'units' => $units ] = $this->buildRepackagedProduct(
            name: 'น้ำตาลทรายขาว แบ่งขาย',
            sizes: self::SIZES,
            prices: [ '100 กรัม' => 5, '200 กรัม' => 9, 'ครึ่งโล' => 22, '1 กก.' => 42, 'กระสอบ 10 กก.' => 390 ],
            baseStock: 100.0
        );

        $service = app( ProductService::class );
        $base = $units[ '100 กรัม' ];

        $this->assertSame(
            100.0,
            (float) $service->getQuantity( $product->id, $base->id, $branchId ),
            'A ten kilo sack is a hundred lots of a hundred grams.'
        );

        $this->sell( $service, $product, $units[ '1 กก.' ], 1, $branchId );

        $this->assertSame(
            90.0,
            (float) $service->getQuantity( $product->id, $base->id, $branchId ),
            'Selling a kilo has to take ten hundred-gram lots off the sack, not one.'
        );

        $this->sell( $service, $product, $units[ 'ครึ่งโล' ], 1, $branchId );

        $this->assertSame( 85.0, (float) $service->getQuantity( $product->id, $base->id, $branchId ) );

        $this->sell( $service, $product, $units[ '200 กรัม' ], 1, $branchId );

        $this->assertSame( 83.0, (float) $service->getQuantity( $product->id, $base->id, $branchId ) );

        $this->sell( $service, $product, $units[ '100 กรัม' ], 1, $branchId );

        $this->assertSame(
            82.0,
            (float) $service->getQuantity( $product->id, $base->id, $branchId ),
            'One kilo, a half, two hundred and a hundred grams is one point eight kilos gone from ten.'
        );
    }

    /**
     * The label rows must never accumulate a figure of their own, or the shelf would appear to
     * hold a sack, plus some kilos, plus some half kilos, all at once.
     */
    public function test_the_larger_sizes_never_hold_a_figure_of_their_own(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        [ 'product' => $product, 'units' => $units ] = $this->buildRepackagedProduct(
            name: 'ถั่วเขียว แบ่งขาย',
            sizes: self::SIZES,
            prices: [ '100 กรัม' => 6, '200 กรัม' => 11, 'ครึ่งโล' => 26, '1 กก.' => 50, 'กระสอบ 10 กก.' => 470 ],
            baseStock: 100.0
        );

        $service = app( ProductService::class );

        $this->sell( $service, $product, $units[ '1 กก.' ], 2, $branchId );

        foreach ( [ '200 กรัม', 'ครึ่งโล', '1 กก.', 'กระสอบ 10 กก.' ] as $label ) {
            $this->assertSame(
                0.0,
                (float) $service->getQuantity( $product->id, $units[ $label ]->id, $branchId ),
                sprintf( 'The %s row started as a label and must stay one.', $label )
            );
        }

        $this->assertSame( 80.0, (float) $service->getQuantity( $product->id, $units[ '100 กรัม' ]->id, $branchId ) );
    }

    /**
     * Selling the whole sack unopened empties the shelf exactly, with nothing left over and no
     * negative remainder.
     */
    public function test_selling_the_whole_sack_empties_the_shelf_exactly(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        [ 'product' => $product, 'units' => $units ] = $this->buildRepackagedProduct(
            name: 'แป้งสาลี แบ่งขาย',
            sizes: self::SIZES,
            prices: [ '100 กรัม' => 7, '200 กรัม' => 13, 'ครึ่งโล' => 30, '1 กก.' => 58, 'กระสอบ 10 กก.' => 540 ],
            baseStock: 100.0
        );

        $service = app( ProductService::class );

        $this->sell( $service, $product, $units[ 'กระสอบ 10 กก.' ], 1, $branchId );

        $this->assertSame(
            0.0,
            (float) $service->getQuantity( $product->id, $units[ '100 กรัม' ]->id, $branchId ),
            'One sack sold whole is the entire shelf.'
        );
    }

    /**
     * The shelf holds three hundred grams. A customer asking for half a kilo cannot be served, and
     * the attempt has to be refused rather than pushing the figure below zero — otherwise the shop
     * sells goods it does not have and only finds out at the next count.
     */
    public function test_a_size_larger_than_what_is_left_is_refused(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        [ 'product' => $product, 'units' => $units ] = $this->buildRepackagedProduct(
            name: 'งาดำ แบ่งขาย',
            sizes: self::SIZES,
            prices: [ '100 กรัม' => 9, '200 กรัม' => 17, 'ครึ่งโล' => 40, '1 กก.' => 78, 'กระสอบ 10 กก.' => 720 ],
            baseStock: 3.0
        );

        $service = app( ProductService::class );

        $refused = false;

        try {
            $this->sell( $service, $product, $units[ 'ครึ่งโล' ], 1, $branchId );
        } catch ( \Throwable $e ) {
            $refused = true;
        }

        $this->assertTrue( $refused, 'Half a kilo was sold off a shelf holding three hundred grams.' );

        $this->assertSame(
            3.0,
            (float) $service->getQuantity( $product->id, $units[ '100 กรัม' ]->id, $branchId ),
            'A refused sale must leave the figure untouched.'
        );
    }

    /**
     * Receiving is stated in whichever size the delivery was counted in. Two sacks arriving on a
     * shelf holding half a kilo has to read as twenty and a half kilos.
     */
    public function test_receiving_two_sacks_adds_them_in_the_counting_size(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        [ 'product' => $product, 'units' => $units ] = $this->buildRepackagedProduct(
            name: 'ข้าวเหนียว แบ่งขาย',
            sizes: self::SIZES,
            prices: [ '100 กรัม' => 8, '200 กรัม' => 15, 'ครึ่งโล' => 35, '1 กก.' => 68, 'กระสอบ 10 กก.' => 630 ],
            baseStock: 5.0
        );

        $service = app( ProductService::class );

        $service->stockAdjustment( ProductHistory::ACTION_ADDED, [
            'product_id' => $product->id,
            'unit_id' => $units[ 'กระสอบ 10 กก.' ]->id,
            'quantity' => 2,
            'unit_price' => 0,
            'branch_id' => $branchId,
            'author' => 1,
        ] );

        $this->assertSame(
            205.0,
            (float) $service->getQuantity( $product->id, $units[ '100 กรัม' ]->id, $branchId ),
            'Half a kilo on the shelf plus two ten kilo sacks is twenty and a half kilos.'
        );
    }

    /**
     * Every movement is recorded against the size the stock is counted in, so a count and the
     * history agree. A ledger written in the sold size would not add up to the figure on the shelf.
     */
    public function test_the_history_records_movements_in_the_counting_size(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        [ 'product' => $product, 'units' => $units ] = $this->buildRepackagedProduct(
            name: 'ลูกเดือย แบ่งขาย',
            sizes: self::SIZES,
            prices: [ '100 กรัม' => 10, '200 กรัม' => 19, 'ครึ่งโล' => 45, '1 กก.' => 88, 'กระสอบ 10 กก.' => 820 ],
            baseStock: 100.0
        );

        $service = app( ProductService::class );

        $this->sell( $service, $product, $units[ '1 กก.' ], 1, $branchId );

        $entry = ProductHistory::where( 'product_id', $product->id )
            ->orderByDesc( 'id' )
            ->first();

        $this->assertNotNull( $entry );

        $this->assertSame(
            (int) $units[ '100 กรัม' ]->id,
            (int) $entry->unit_id,
            'The ledger has to speak in the size the shelf is counted in.'
        );

        $this->assertSame( 10.0, (float) $entry->quantity, 'A kilo is ten of the hundred gram lots.' );
        $this->assertSame( 100.0, (float) $entry->before_quantity );
        $this->assertSame( 90.0, (float) $entry->after_quantity );
    }
}
