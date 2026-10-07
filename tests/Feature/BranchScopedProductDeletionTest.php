<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Deleting a product in one branch must not empty another branch's shelf.
 *
 * A product is a single company-wide row; only its stock, prices and barcodes are held per branch on
 * nexopos_products_unit_quantities.branch_id. Deleting the product therefore ran
 * ProductService::deleteProductRelations(), whose unit_quantities()->delete() carries no branch
 * condition — so the administrator removing an item from the main warehouse destroyed every other
 * branch's quantity, price and barcode for it, silently and with nothing to undo it with. On the shop
 * that reported this, 935 products were stocked in both branches at once.
 *
 * The stock row is what makes a product exist for a branch: the till's own search only offers a
 * product when the searcher's branch holds a quantity row for it. So removing this branch's rows is
 * exactly "this branch stops stocking it", and the catalogue entry survives for the branches that
 * still sell it. Deleting the product outright still happens — once no other branch holds a row.
 *
 * Everything here goes through DELETE /api/crud/ns.products, the same endpoint the button on the
 * product list calls, so the branch resolution, the guards and the response are exercised together.
 */
class BranchScopedProductDeletionTest extends TestCase
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

        foreach ( array_reverse( $this->createdRows ) as [ $table, $id ] ) {
            DB::table( $table )->where( 'id', $id )->delete();
        }

        $this->createdRows = [];

        session()->forget( 'selected_branch_id' );

        parent::tearDown();
    }

    /**
     * The fault exactly as the shop reported it.
     */
    public function test_removing_a_product_from_one_branch_leaves_the_other_branch_untouched(): void
    {
        [ $product, $unit, $branches ] = $this->productStockedInTwoBranches();

        $this->standInBranch( $branches[ 0 ] );

        $this->deleteProduct( $product )->assertOk();

        $this->assertSame(
            0,
            $this->rowCount( $product, $branches[ 0 ] ),
            'The branch the delete was issued from still holds stock, so nothing was removed.'
        );

        $this->assertSame(
            1,
            $this->rowCount( $product, $branches[ 1 ] ),
            'Deleting the product in one branch wiped another branch\'s shelf. That is the reported'
                . ' fault: the administrator removes an item from the main warehouse and every branch'
                . ' loses its quantity, price and barcode for it.'
        );

        $this->assertSame(
            200.0,
            (float) ProductUnitQuantity::where( 'product_id', $product->id )
                ->where( 'branch_id', $branches[ 1 ] )
                ->value( 'quantity' ),
            'the surviving branch\'s quantity was altered'
        );

        $this->assertNotNull(
            Product::find( $product->id ),
            'The catalogue entry was deleted while another branch still stocks the item, so that'
                . ' branch can no longer sell something it physically has on the shelf.'
        );
    }

    /**
     * Deleting still means deleting. Once no other branch holds a row there is nothing left to
     * protect, and the product goes — which is also how a company-wide delete is reached: branch by
     * branch.
     */
    public function test_the_product_is_deleted_outright_once_no_other_branch_stocks_it(): void
    {
        [ $product, $unit, $branches ] = $this->productStockedInTwoBranches();

        $this->standInBranch( $branches[ 0 ] );
        $this->deleteProduct( $product )->assertOk();

        $this->assertNotNull( Product::find( $product->id ), 'the product went on the first pass' );

        $this->standInBranch( $branches[ 1 ] );
        $this->deleteProduct( $product )->assertOk();

        $this->assertNull(
            Product::find( $product->id ),
            'The last branch stopped stocking the item and the catalogue entry stayed behind, so there'
                . ' is no way to remove a product from the system at all.'
        );

        $this->assertSame(
            0,
            ProductUnitQuantity::where( 'product_id', $product->id )->count(),
            'stock rows outlived the product they belong to'
        );
    }

    /**
     * The product list is company-wide on purpose — a branch has to see an item before it can start
     * stocking it — so another branch's goods are visible from here. They must not be deletable from
     * here.
     */
    public function test_a_product_stocked_only_in_another_branch_cannot_be_deleted_from_here(): void
    {
        [ $product, $unit, $branches ] = $this->productStockedInTwoBranches();

        ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'branch_id', $branches[ 0 ] )
            ->delete();

        $this->standInBranch( $branches[ 0 ] );

        $this->deleteProduct( $product )->assertStatus( 403 );

        $this->assertSame(
            1,
            $this->rowCount( $product, $branches[ 1 ] ),
            'A branch that does not stock the item was able to delete it out of the branch that does.'
        );

        $this->assertNotNull( Product::find( $product->id ) );
    }

    /**
     * A row carrying no branch belongs to every branch at once, so there is no per-branch row to
     * remove instead. Guessing here would reproduce the original fault, so the request is refused.
     */
    public function test_a_product_whose_stock_is_not_split_by_branch_is_refused(): void
    {
        [ $product, $unit, $branches ] = $this->productStockedInTwoBranches();

        $this->unitQuantity( $product->id, $unit->id, branchId: null, quantity: 15 );

        $this->standInBranch( $branches[ 0 ] );

        $this->deleteProduct( $product )->assertStatus( 403 );

        $this->assertSame(
            3,
            ProductUnitQuantity::where( 'product_id', $product->id )->count(),
            'A shared stock row was removed on behalf of one branch, which takes the item away from'
                . ' every branch — the fault this whole change exists to prevent.'
        );
    }

    /**
     * Refunds read back the unit quantity row a line was sold against. A branch that has sold the
     * item keeps its row and zeroes the quantity instead, or its own past bills become unrefundable.
     */
    public function test_a_branch_that_has_already_sold_the_product_keeps_its_row(): void
    {
        [ $product, $unit, $branches ] = $this->productStockedInTwoBranches();

        $this->sellFromBranch( $product, $branches[ 0 ] );

        $this->standInBranch( $branches[ 0 ] );

        $this->deleteProduct( $product )->assertStatus( 403 );

        $this->assertSame(
            1,
            $this->rowCount( $product, $branches[ 0 ] ),
            'The row a past bill was sold against was removed, so that bill can no longer be refunded.'
        );
    }

    /**
     * A sale in one branch must not freeze the item for another. Before this, the dependency guard
     * refused company-wide, so one branch selling an item meant no branch could ever stop stocking it.
     */
    public function test_a_sale_in_another_branch_does_not_block_this_one(): void
    {
        [ $product, $unit, $branches ] = $this->productStockedInTwoBranches();

        $this->sellFromBranch( $product, $branches[ 1 ] );

        $this->standInBranch( $branches[ 0 ] );

        $this->deleteProduct( $product )->assertOk();

        $this->assertSame( 0, $this->rowCount( $product, $branches[ 0 ] ) );
        $this->assertSame( 1, $this->rowCount( $product, $branches[ 1 ] ) );
    }

    /**
     * Selecting rows and pressing delete has to mean what pressing delete on one row means, or the
     * bulk action becomes the way round the rule — and the way to wipe every branch in one click.
     */
    public function test_the_bulk_delete_applies_the_same_branch_rule(): void
    {
        [ $first, $firstUnit, $branches ] = $this->productStockedInTwoBranches();
        [ $second ] = $this->productStockedInTwoBranches( $branches );

        $this->standInBranch( $branches[ 0 ] );

        $response = $this->actingAs( $this->actor(), 'web' )
            ->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', '/api/crud/ns.products/bulk-actions', [
                'action' => 'delete_selected',
                'entries' => [ $first->id, $second->id ],
            ] );

        $response->assertOk();

        foreach ( [ $first, $second ] as $product ) {
            $this->assertSame(
                0,
                $this->rowCount( $product, $branches[ 0 ] ),
                'the bulk action did not remove the acting branch\'s stock'
            );

            $this->assertSame(
                1,
                $this->rowCount( $product, $branches[ 1 ] ),
                'The bulk delete wiped another branch\'s shelf, so the single-row rule can be bypassed'
                    . ' by ticking the checkboxes instead.'
            );

            $this->assertNotNull( Product::find( $product->id ) );
        }
    }

    /**
     * The other way a stock row can be removed: the procurement page deletes one by id, and an id
     * says nothing about whose shelf it is. Branch staff removing another branch's row would take
     * goods off a shelf they cannot even see, and write the adjustment against the wrong history.
     */
    public function test_branch_staff_cannot_delete_another_branch_stock_row_by_id(): void
    {
        [ $product, $unit, $branches ] = $this->productStockedInTwoBranches();

        $theirRow = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'branch_id', $branches[ 1 ] )
            ->firstOrFail();

        $actor = $this->actor();

        DB::table( 'nexopos_users' )->where( 'id', $actor->id )->update( [ 'branch_id' => $branches[ 0 ] ] );
        $actor->refresh();

        $response = $this->actingAs( $actor, 'web' )
            ->json( 'DELETE', '/api/products/units/quantity/' . $theirRow->id );

        $response->assertStatus( 403 );

        /**
         * The message is asserted, not just the status. Removing the guard still produced a 403 from
         * further down the stock adjustment, so a status-only assertion passed against an unguarded
         * endpoint — it proved nothing.
         */
        $this->assertStringContainsString(
            'เป็นของสาขาอื่น',
            (string) $response->json( 'message' ),
            'The refusal did not come from the branch rule, so the ownership check is not what stopped'
                . ' this request.'
        );

        $this->assertSame(
            1,
            $this->rowCount( $product, $branches[ 1 ] ),
            'A member of staff in one branch removed another branch\'s stock row.'
        );
    }

    /**
     * The same endpoint has to keep working on the rows that do belong to the branch, or the
     * procurement page stops being able to correct its own stock.
     */
    public function test_branch_staff_can_still_delete_their_own_stock_row(): void
    {
        [ $product, $unit, $branches ] = $this->productStockedInTwoBranches();

        $ownRow = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'branch_id', $branches[ 0 ] )
            ->firstOrFail();

        $actor = $this->actor();

        DB::table( 'nexopos_users' )->where( 'id', $actor->id )->update( [ 'branch_id' => $branches[ 0 ] ] );
        $actor->refresh();

        $this->actingAs( $actor, 'web' )
            ->json( 'DELETE', '/api/products/units/quantity/' . $ownRow->id )
            ->assertOk();

        $this->assertSame( 0, $this->rowCount( $product, $branches[ 0 ] ) );
        $this->assertSame( 1, $this->rowCount( $product, $branches[ 1 ] ) );
    }

    /**
     * A shop that has only ever had one branch carries no branch on any row, and there is nothing to
     * scope. Deleting has to keep working there exactly as it did.
     */
    public function test_a_single_store_install_still_deletes_the_product(): void
    {
        $group = $this->unitGroup();
        $unit = $this->unit( $group->id );
        $product = $this->product( $group->id );

        $this->unitQuantity( $product->id, $unit->id, branchId: null, quantity: 40 );

        $this->standInBranch( null );
        session()->forget( 'selected_branch_id' );

        $this->deleteProduct( $product )->assertOk();

        $this->assertNull(
            Product::find( $product->id ),
            'A single store install can no longer delete a product at all.'
        );
    }

    // ------------------------------------------------------------------ helpers

    private function deleteProduct( Product $product )
    {
        return $this->actingAs( $this->actor(), 'web' )
            ->withSession( $this->app[ 'session' ]->all() )
            ->json( 'DELETE', '/api/crud/ns.products/' . $product->id );
    }

    private function rowCount( Product $product, int $branchId ): int
    {
        return ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'branch_id', $branchId )
            ->count();
    }

    /**
     * @param  list<int>|null $branches
     * @return array{0: Product, 1: Unit, 2: list<int>}
     */
    private function productStockedInTwoBranches( ?array $branches = null ): array
    {
        $branches ??= [
            $this->makeBranch( 'สาขาลบหนึ่ง' ),
            $this->makeBranch( 'สาขาลบสอง' ),
        ];

        $group = $this->unitGroup();
        $unit = $this->unit( $group->id );
        $product = $this->product( $group->id );

        foreach ( [ 100.0, 200.0 ] as $index => $quantity ) {
            $this->unitQuantity( $product->id, $unit->id, $branches[ $index ], $quantity );
        }

        return [ $product, $unit, $branches ];
    }

    private function sellFromBranch( Product $product, int $branchId ): void
    {
        $unitQuantity = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'branch_id', $branchId )
            ->firstOrFail();

        $orderId = (int) DB::table( 'nexopos_orders' )->insertGetId( [
            'code' => 'DEL-' . random_int( 100000, 999999 ),
            'type' => 'takeaway',
            'branch_id' => $branchId,
            'payment_status' => 'paid',
            'author_id' => $this->actor()->id,
            /**
             * Customers are rows of nexopos_users in this schema, so the acting user stands in as the
             * buyer. Nothing here reads the customer, the column only has to be filled.
             */
            'customer_id' => $this->actor()->id,
            'subtotal' => 10,
            'total' => 10,
            'discount' => 0,
            'shipping' => 0,
            'tax_value' => 0,
            'change' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_orders', $orderId ];

        $lineId = (int) DB::table( 'nexopos_orders_products' )->insertGetId( [
            'order_id' => $orderId,
            'product_id' => $product->id,
            'unit_id' => $unitQuantity->unit_id,
            'unit_quantity_id' => $unitQuantity->id,
            /**
             * Not null in the schema and never read by anything under test here, so the product's own
             * category stands in and falls back to zero when it has none.
             */
            'product_category_id' => (int) ( $product->category_id ?: 0 ),
            'name' => $product->name,
            'quantity' => 1,
            'unit_price' => 10,
            'total_price' => 10,
            'tax_value' => 0,
            'discount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_orders_products', $lineId ];
    }

    private function makeBranch( string $name ): int
    {
        $id = (int) DB::table( 'nexopos_branches' )->insertGetId( [
            'code' => 'DEL' . random_int( 100000, 999999 ),
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
        $group->name = 'del-' . random_int( 100000, 999999 );
        $group->author_id = 1;
        $group->save();

        $this->createdRows[] = [ 'nexopos_units_groups', $group->id ];

        return $group;
    }

    private function unit( int $groupId ): Unit
    {
        $unit = new Unit;
        $unit->name = 'ชิ้นลบ ' . random_int( 100000, 999999 );
        $unit->identifier = 'del-' . random_int( 100000, 999999 );
        $unit->group_id = $groupId;
        $unit->value = 1;
        $unit->base_unit = true;
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
        $product->name = 'สินค้าทดสอบลบ ' . $suffix;
        $product->barcode = 'DEL' . $suffix;
        $product->sku = 'del-' . $suffix;
        $product->barcode_type = 'code128';
        $product->unit_group = $unitGroupId;
        $product->author_id = 1;
        $product->type = Product::TYPE_MATERIALIZED;
        $product->product_type = 'product';
        $product->status = Product::STATUS_AVAILABLE;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->tax_type = 'inclusive';
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->searchable = true;
        $product->save();

        $this->createdRows[] = [ 'nexopos_products', $product->id ];

        return $product;
    }

    private function unitQuantity( int $productId, int $unitId, ?int $branchId, float $quantity ): void
    {
        $row = new ProductUnitQuantity;
        $row->product_id = $productId;
        $row->unit_id = $unitId;
        $row->branch_id = $branchId;
        $row->quantity = $quantity;
        $row->barcode = 'DELQ' . random_int( 100000, 999999 );
        $row->sale_price = 10;
        $row->sale_price_edit = 10;
        $row->wholesale_price = 10;
        $row->cogs = 10;
        $row->type = 'product';
        $row->visible = true;
        $row->stock_alert_enabled = false;
        $row->low_quantity = 0;
        $row->save();

        $this->createdRows[] = [ 'nexopos_products_unit_quantities', $row->id ];
    }

    /**
     * The branch the person pressing delete is standing in, set the way a real request sets it: on
     * the user record for branch staff, and in the session for the administrator, who has no branch
     * of their own and picks one at the top of the screen.
     */
    private function standInBranch( ?int $branchId ): void
    {
        $actor = $this->actor();

        DB::table( 'nexopos_users' )->where( 'id', $actor->id )->update( [ 'branch_id' => null ] );

        $actor->refresh();

        if ( $branchId === null ) {
            session()->forget( 'selected_branch_id' );

            return;
        }

        session( [ 'selected_branch_id' => $branchId ] );
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
