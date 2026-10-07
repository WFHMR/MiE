<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use App\Services\ProductCostEditorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * แก้ต้นทุนสินค้าหลายรายการ — editing cost for many products at once.
 *
 * The product form edits one product at a time. On the shop this was built for, 419 of 7,997 unit
 * rows carry no cost and 394 of those hold goods on the shelf, so every sale of them is reported
 * with the whole sale price as profit and a sale below the real cost cannot be seen at all.
 * Correcting that one form at a time is not work anybody finishes.
 *
 * What is pinned here is the part that can quietly corrupt money: cost is held per unit per branch,
 * so a figure typed while standing in one branch must land on that branch's row and nowhere else,
 * and a row belonging to another branch must be refused outright rather than written — the form
 * never offers it, but the endpoint is reachable by hand. Alongside that: deriving the sibling
 * unit's cost from the one that was typed, never overwriting a figure the same save stated
 * explicitly, refusing nonsense instead of storing zero, and leaving a record of who changed what.
 */
class ProductCostEditorTest extends TestCase
{
    use WithAuthentication;

    /** @var list<array{0: string, 1: int}> */
    private array $createdRows = [];

    private ?User $actor = null;

    private ?int $branchBefore = null;

    protected function tearDown(): void
    {
        if ( $this->actor instanceof User ) {
            DB::table( 'nexopos_users' )->where( 'id', $this->actor->id )->update( [ 'branch_id' => $this->branchBefore ] );
        }

        AuditTrail::where( 'resource', 'ns.products-cost' )->delete();

        foreach ( array_reverse( $this->createdRows ) as [ $table, $id ] ) {
            DB::table( $table )->where( 'id', $id )->delete();
        }

        $this->createdRows = [];

        session()->forget( 'selected_branch_id' );

        parent::tearDown();
    }

    public function test_the_page_opens_and_offers_only_this_branch_rows(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $mine = $this->row( $product, $units[ 0 ], $branches[ 1 ] );
        $theirs = $this->row( $product, $units[ 0 ], $branches[ 0 ] );

        $response = $this->openPage( $product, $branches[ 1 ] );

        $response->assertStatus( 200 );
        $response->assertSee( $product->name, false );

        $response->assertSee( 'costs[' . $mine->id . ']', false );

        $response->assertDontSee(
            'costs[' . $theirs->id . ']',
            false
        );
    }

    /**
     * The whole point of the screen: type figures for several rows, press save once.
     */
    public function test_several_costs_are_saved_in_one_submission(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $pieces = $this->row( $product, $units[ 0 ], $branches[ 1 ] );
        $cases = $this->row( $product, $units[ 1 ], $branches[ 1 ] );

        $this->save( $branches[ 1 ], [
            $pieces->id => '11.25',
            $cases->id => '270',
        ], spread: false )->assertRedirect();

        $this->assertEqualsWithDelta( 11.25, (float) $pieces->fresh()->cogs, 0.0001, 'the first row was not saved' );
        $this->assertEqualsWithDelta( 270.0, (float) $cases->fresh()->cogs, 0.0001, 'the second row was not saved' );
    }

    /**
     * A cost belongs to a branch.
     */
    public function test_a_cost_typed_in_one_branch_does_not_touch_another_branch(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $mine = $this->row( $product, $units[ 0 ], $branches[ 1 ] );
        $theirs = $this->row( $product, $units[ 0 ], $branches[ 0 ] );
        $theirsBefore = (float) $theirs->cogs;

        $this->save( $branches[ 1 ], [ $mine->id => '33' ], spread: false )->assertRedirect();

        $this->assertEqualsWithDelta( 33.0, (float) $mine->fresh()->cogs, 0.0001, 'the branch being edited was not saved' );

        $this->assertEqualsWithDelta(
            $theirsBefore,
            (float) $theirs->fresh()->cogs,
            0.0001,
            'Editing one branch rewrote another branch\'s cost, so a figure corrected at one shop'
                . ' changes what a different shop believes it paid.'
        );
    }

    /**
     * The form never shows another branch's row, but the endpoint is reachable by hand.
     */
    public function test_a_row_belonging_to_another_branch_is_refused(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $theirs = $this->row( $product, $units[ 0 ], $branches[ 0 ] );
        $before = (float) $theirs->cogs;

        $result = $this->applyAs( $branches[ 1 ], [ $theirs->id => '999' ] );

        $this->assertSame( 0, $result[ 'updated' ], 'another branch\'s row was written' );
        $this->assertSame( 1, $result[ 'refused' ], 'the refusal was not counted, so nobody is told it was skipped' );

        $this->assertEqualsWithDelta(
            $before,
            (float) $theirs->fresh()->cogs,
            0.0001,
            'A row stamped with a different branch was written from a branch the user is not standing'
                . ' in. Posting the form by hand could rewrite every branch\'s cost.'
        );
    }

    /**
     * Costing the case states the cost of the piece. Leaving the sibling at zero is the bug this
     * screen exists to end.
     */
    public function test_the_other_units_of_the_product_are_derived_from_what_was_typed(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInOneBranchWithNoCost();

        $cases = $this->row( $product, $units[ 1 ], $branches[ 0 ] );
        $pieces = $this->row( $product, $units[ 0 ], $branches[ 0 ] );

        $result = $this->applyAs( $branches[ 0 ], [ $cases->id => '240' ] );

        $this->assertSame( 1, $result[ 'updated' ] );
        $this->assertSame( 1, $result[ 'spread' ], 'no sibling unit was derived, so the piece still has no cost' );

        $this->assertEqualsWithDelta(
            10.0,
            (float) $pieces->fresh()->cogs,
            0.0001,
            'A case of 24 costing 240 means a piece costs 10. Without this the piece keeps a cost of'
                . ' zero and its sales still report the whole price as profit.'
        );
    }

    /**
     * Two figures typed in one save are two facts. Neither may be replaced by arithmetic from the
     * other, and which one survived must not depend on row order.
     */
    public function test_a_unit_typed_in_the_same_save_is_not_overwritten_by_the_derivation(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInOneBranchWithNoCost();

        $cases = $this->row( $product, $units[ 1 ], $branches[ 0 ] );
        $pieces = $this->row( $product, $units[ 0 ], $branches[ 0 ] );

        $result = $this->applyAs( $branches[ 0 ], [
            $cases->id => '240',
            $pieces->id => '11',
        ] );

        $this->assertSame( 2, $result[ 'updated' ] );

        $this->assertEqualsWithDelta(
            11.0,
            (float) $pieces->fresh()->cogs,
            0.0001,
            'The figure typed for the piece was replaced by one derived from the case. Somebody'
                . ' correcting both units of a product in one save would have one of the two silently'
                . ' discarded.'
        );

        $this->assertEqualsWithDelta( 240.0, (float) $cases->fresh()->cogs, 0.0001, 'the case cost was not kept' );
    }

    /**
     * The form posts an input for every visible row, so blank has to mean "leave alone". Reading it
     * as zero would wipe the cost of every row on screen that the user did not touch.
     */
    public function test_a_blank_box_leaves_the_row_alone(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $row = $this->row( $product, $units[ 0 ], $branches[ 1 ] );
        $before = (float) $row->cogs;

        $this->assertGreaterThan( 0, $before, 'the fixture has no cost to preserve' );

        $result = $this->applyAs( $branches[ 1 ], [ $row->id => '' ] );

        $this->assertSame( 0, $result[ 'updated' ] );

        $this->assertEqualsWithDelta(
            $before,
            (float) $row->fresh()->cogs,
            0.0001,
            'A row left blank was written. Saving a page of fifty rows after typing one figure would'
                . ' erase the other forty-nine.'
        );
    }

    public function test_nonsense_is_refused_rather_than_stored_as_zero(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $row = $this->row( $product, $units[ 0 ], $branches[ 1 ] );
        $before = (float) $row->cogs;

        foreach ( [ '-5', 'abc', '12..5' ] as $submitted ) {
            $result = $this->applyAs( $branches[ 1 ], [ $row->id => $submitted ] );

            $this->assertSame( 1, $result[ 'invalid' ], '"' . $submitted . '" was accepted as a cost' );
            $this->assertSame( 0, $result[ 'updated' ] );

            $this->assertEqualsWithDelta(
                $before,
                (float) $row->fresh()->cogs,
                0.0001,
                '"' . $submitted . '" changed the stored cost. A typo stored as zero reads as a free'
                    . ' product for the rest of the year.'
            );
        }
    }

    /**
     * A figure copied out of a spreadsheet carries its separators.
     */
    public function test_a_thousands_separator_is_accepted(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $row = $this->row( $product, $units[ 1 ], $branches[ 1 ] );

        $this->applyAs( $branches[ 1 ], [ $row->id => '1,250.50' ] );

        $this->assertEqualsWithDelta( 1250.5, (float) $row->fresh()->cogs, 0.0001, 'a pasted figure with a comma was rejected' );
    }

    public function test_resubmitting_the_same_figure_is_not_counted_as_a_change(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $row = $this->row( $product, $units[ 0 ], $branches[ 1 ] );

        $result = $this->applyAs( $branches[ 1 ], [ $row->id => (string) (float) $row->cogs ] );

        $this->assertSame( 0, $result[ 'updated' ], 'an unchanged row was written' );
        $this->assertSame( 1, $result[ 'unchanged' ] );
    }

    /**
     * A row carrying no branch belongs to every branch. It stays editable — that is the single store
     * case and the rows predating the split — and the screen marks it.
     */
    public function test_a_row_with_no_branch_is_editable_and_marked_on_screen(): void
    {
        $group = $this->unitGroup();
        $units = [ $this->unit( $group->id, 1 ) ];
        $product = $this->product( $group->id );
        $branch = $this->makeBranch( 'สาขาต้นทุนไม่ระบุ' );

        $this->unitQuantity( $product->id, $units[ 0 ]->id, null, cogs: 0, salePrice: 25, quantity: 4 );

        $row = $this->row( $product, $units[ 0 ], null );

        $this->openPage( $product, $branch )
            ->assertStatus( 200 )
            ->assertSee( 'costs[' . $row->id . ']', false )
            ->assertSee( 'ทุกสาขา', false );

        $result = $this->applyAs( $branch, [ $row->id => '7.5' ] );

        $this->assertSame( 1, $result[ 'updated' ] );
        $this->assertEqualsWithDelta( 7.5, (float) $row->fresh()->cogs, 0.0001 );
    }

    public function test_only_missing_shows_the_rows_with_no_cost(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $priced = $this->row( $product, $units[ 0 ], $branches[ 1 ] );

        $withoutCost = $this->row( $product, $units[ 1 ], $branches[ 1 ] );
        $withoutCost->cogs = 0;
        $withoutCost->save();

        $this->actingAsActor( $branches[ 1 ] )
            ->get( route( 'ns.products-cost-editor', [
                'filtered' => 1,
                'search' => $product->name,
                'only_missing' => 1,
                'only_in_stock' => 0,
            ] ) )
            ->assertStatus( 200 )
            ->assertSee( 'costs[' . $withoutCost->id . ']', false )
            ->assertDontSee( 'costs[' . $priced->id . ']', false );
    }

    /**
     * Changing a cost changes reported profit, so it has to be attributable.
     */
    public function test_the_change_is_recorded_in_the_audit_trail(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $row = $this->row( $product, $units[ 0 ], $branches[ 1 ] );
        $before = round( (float) $row->cogs, 5 );

        $this->applyAs( $branches[ 1 ], [ $row->id => '77.5' ] );

        $entry = AuditTrail::where( 'resource', 'ns.products-cost' )->latest( 'id' )->first();

        $this->assertNotNull( $entry, 'a cost change left no trace of who made it' );

        $this->assertStringContainsString(
            $row->id . ':' . $before . '>77.5',
            (string) $entry->changes,
            'The audit row does not say which row moved from what to what, so the record cannot answer'
                . ' why a margin changed.'
        );

        $this->assertNotEmpty( $entry->user_name, 'the audit row does not name who did it' );
    }

    /**
     * Held to the same permission as editing the product, and refused on the URL rather than only
     * hidden from the menu.
     */
    public function test_somebody_without_permission_to_edit_products_is_refused(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $row = $this->row( $product, $units[ 0 ], $branches[ 1 ] );
        $before = (float) $row->cogs;

        $cashier = User::whereHas( 'roles', fn ( $query ) => $query->where( 'namespace', Role::STORECASHIER ) )->first();

        $this->assertNotNull( $cashier, 'there is no cashier in the test data to check the refusal with' );

        $this->assertNotEquals(
            200,
            $this->actingAs( $cashier, 'web' )
                ->get( route( 'ns.products-cost-editor' ) )
                ->getStatusCode(),
            'A cashier can open the screen that sets what every margin is computed from.'
        );

        $this->actingAs( $cashier, 'web' )
            ->post( route( 'ns.products-cost-editor.save' ), [
                'costs' => [ $row->id => '1' ],
                'spread_to_other_units' => 0,
            ] );

        $this->assertEqualsWithDelta(
            $before,
            (float) $row->fresh()->cogs,
            0.0001,
            'A cashier changed a cost by posting the form directly.'
        );
    }

    // ------------------------------------------------------------------ helpers

    private function openPage( Product $product, ?int $branchId )
    {
        return $this->actingAsActor( $branchId )
            ->get( route( 'ns.products-cost-editor', [
                'filtered' => 1,
                'search' => $product->name,
                'only_missing' => 0,
                'only_in_stock' => 0,
            ] ) );
    }

    /**
     * @param array<int, string> $costs
     */
    private function save( ?int $branchId, array $costs, bool $spread )
    {
        return $this->actingAsActor( $branchId )
            ->post( route( 'ns.products-cost-editor.save' ), [
                'costs' => $costs,
                'spread_to_other_units' => $spread ? 1 : 0,
            ] );
    }

    /**
     * The service called directly, for the assertions that are about the counts it returns rather
     * than about the page.
     *
     * @param  array<int, string> $costs
     * @return array{updated: int, unchanged: int, refused: int, invalid: int, spread: int, refusals: array<int, string>}
     */
    private function applyAs( ?int $branchId, array $costs, bool $spread = true ): array
    {
        $this->actingAsActor( $branchId );

        return app( ProductCostEditorService::class )->apply( $costs, $spread );
    }

    private function actingAsActor( ?int $branchId ): static
    {
        $actor = $this->actor();

        DB::table( 'nexopos_users' )->where( 'id', $actor->id )->update( [ 'branch_id' => null ] );
        $actor->refresh();

        if ( $branchId === null ) {
            session()->forget( 'selected_branch_id' );
        } else {
            session( [ 'selected_branch_id' => $branchId ] );
        }

        /**
         * The web guard, not Sanctum: these are dashboard pages, and a token-guarded request has no
         * viaRemember, which is what the dashboard middleware asks the guard for.
         */
        return $this->actingAs( $actor, 'web' )->withSession( $this->app[ 'session' ]->all() );
    }

    private function row( Product $product, Unit $unit, ?int $branchId ): ?ProductUnitQuantity
    {
        return ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $unit->id )
            ->when(
                $branchId === null,
                fn ( $query ) => $query->whereNull( 'branch_id' ),
                fn ( $query ) => $query->where( 'branch_id', $branchId )
            )
            ->first();
    }

    /**
     * Two branches, each holding its own cost for the same two units, whose values are 1 and 24.
     *
     * @return array{0: Product, 1: list<Unit>, 2: list<int>}
     */
    private function productStockedInTwoBranches(): array
    {
        $group = $this->unitGroup();
        $units = [ $this->unit( $group->id, 1 ), $this->unit( $group->id, 24 ) ];
        $product = $this->product( $group->id );

        $branches = [ $this->makeBranch( 'สาขาต้นทุนหนึ่ง' ), $this->makeBranch( 'สาขาต้นทุนสอง' ) ];

        foreach ( [ 0 => 20.0, 1 => 40.0 ] as $index => $cogs ) {
            foreach ( $units as $unit ) {
                $this->unitQuantity(
                    $product->id,
                    $unit->id,
                    $branches[ $index ],
                    cogs: $cogs * (float) $unit->value,
                    salePrice: ( $index === 0 ? 30.0 : 50.0 ) * (float) $unit->value,
                    quantity: 5
                );
            }
        }

        return [ $product, $units, $branches ];
    }

    /**
     * One branch, both units carrying no cost at all — the state 394 rows of the live shop are in.
     *
     * @return array{0: Product, 1: list<Unit>, 2: list<int>}
     */
    private function productStockedInOneBranchWithNoCost(): array
    {
        $group = $this->unitGroup();
        $units = [ $this->unit( $group->id, 1 ), $this->unit( $group->id, 24 ) ];
        $product = $this->product( $group->id );
        $branches = [ $this->makeBranch( 'สาขาต้นทุนศูนย์' ) ];

        foreach ( $units as $unit ) {
            $this->unitQuantity(
                $product->id,
                $unit->id,
                $branches[ 0 ],
                cogs: 0,
                salePrice: 30.0 * (float) $unit->value,
                quantity: 8
            );
        }

        return [ $product, $units, $branches ];
    }

    private function makeBranch( string $name ): int
    {
        $id = (int) DB::table( 'nexopos_branches' )->insertGetId( [
            'code' => 'CSTE' . random_int( 100000, 999999 ),
            'name' => $name . ' ' . random_int( 100, 999 ),
            'is_main' => false,
            'is_active' => true,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_branches', $id ];

        return $id;
    }

    private function unitGroup(): UnitGroup
    {
        $group = new UnitGroup;
        $group->name = 'กลุ่มหน่วยแก้ต้นทุน ' . random_int( 100000, 999999 );
        $group->author_id = 1;
        $group->save();

        $this->createdRows[] = [ 'nexopos_units_groups', $group->id ];

        return $group;
    }

    private function unit( int $groupId, float $value ): Unit
    {
        $unit = new Unit;
        $unit->name = ( $value === 1.0 ? 'ชิ้นแก้ทุน ' : 'ลังแก้ทุน ' ) . random_int( 100000, 999999 );
        $unit->identifier = 'cost-editor-' . random_int( 100000, 999999 );
        $unit->group_id = $groupId;
        $unit->value = $value;
        $unit->base_unit = $value === 1.0;
        $unit->author_id = 1;
        $unit->save();

        $this->createdRows[] = [ 'nexopos_units', $unit->id ];

        return $unit;
    }

    private function product( int $unitGroupId ): Product
    {
        $suffix = random_int( 100000, 999999 );

        $product = new Product;
        $product->stock_on_base_unit = false;

        /**
         * A name nothing else in the catalogue shares, because the page is reached through its own
         * search box and a shared word would pull real products into the assertions.
         */
        $product->name = 'สินค้าทดสอบแก้ต้นทุน' . $suffix;
        $product->barcode = 'CSTE' . $suffix;
        $product->sku = 'cost-editor-' . Str::random( 8 ) . '-' . $suffix;
        $product->barcode_type = 'code128';
        $product->unit_group = $unitGroupId;
        $product->author_id = 1;
        $product->category_id = DB::table( 'nexopos_products_categories' )->value( 'id' );
        $product->tax_group_id = TaxGroup::firstOrFail()->id;
        $product->type = Product::TYPE_MATERIALIZED;
        $product->product_type = 'product';
        $product->status = Product::STATUS_AVAILABLE;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->tax_type = 'inclusive';
        $product->auto_cogs = true;
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->searchable = true;
        $product->save();

        $this->createdRows[] = [ 'nexopos_products', $product->id ];

        return $product;
    }

    private function unitQuantity( int $productId, int $unitId, ?int $branchId, float $cogs, float $salePrice, float $quantity = 0 ): void
    {
        $row = new ProductUnitQuantity;
        $row->product_id = $productId;
        $row->unit_id = $unitId;
        $row->branch_id = $branchId;
        $row->quantity = $quantity;
        $row->barcode = 'CSTEQ' . random_int( 100000, 999999 );
        $row->sale_price = $salePrice;
        $row->sale_price_edit = $salePrice;
        $row->wholesale_price = $salePrice;
        $row->wholesale_price_edit = $salePrice;
        $row->cogs = $cogs;
        $row->type = 'product';
        $row->visible = true;
        $row->stock_alert_enabled = false;
        $row->low_quantity = 0;
        $row->save();

        $this->createdRows[] = [ 'nexopos_products_unit_quantities', $row->id ];
    }

    private function actor(): User
    {
        if ( ! $this->actor instanceof User ) {
            $this->actor = $this->attemptGetAnyUserFromRole();
            $this->branchBefore = $this->actor->branch_id;
        }

        return $this->actor;
    }
}
