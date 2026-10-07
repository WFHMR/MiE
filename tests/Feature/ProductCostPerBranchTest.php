<?php

namespace Tests\Feature;

use App\Crud\ProductCrud;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The cost of a product is held per branch, and the shop has to be able to edit it.
 *
 * A product is one company-wide row, but its prices, its cost and its barcodes live in
 * nexopos_products_unit_quantities, one row per unit per branch. Three things ignored that, and
 * together they made the cost impossible to correct from the product form:
 *
 * 1. The form listed every row of the product, so an item stocked in two branches showed each of its
 *    units twice. Saving was then refused outright with "You cannot assign the same unit to more than
 *    one selling unit." — 2,093 of the shop's products are stocked in both branches and not one of
 *    them could be edited at all. The figures on screen were also whichever branch's row came first
 *    out of the database rather than the branch being looked at.
 *
 * 2. getUnitQuantity() resolved the branch through an option that is not switched on here, so the
 *    filter was inert: a save made at Tha Chang wrote the sale price, the wholesale price and the
 *    cost onto the main warehouse's row, and a row created by a save carried no branch at all.
 *
 * 3. computeCogs() averaged both branches' stock history together and wrote the result to whichever
 *    row came first, so every delivery received at Tha Chang recomputed the main warehouse's cost and
 *    left Tha Chang's own row at zero. 167 of its rows were still carrying no cost, which is why its
 *    sales reported a loss.
 */
class ProductCostPerBranchTest extends TestCase
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
     * The form the customer opens to correct a cost. Each unit once, with this branch's figures.
     */
    public function test_the_edit_form_lists_each_unit_once_with_the_branch_own_figures(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $this->attemptAuthenticate( $this->actor() );
        $this->standInBranch( $branches[ 1 ] );

        $groups = $this->sellingGroups( $product );

        $this->assertCount(
            2,
            $groups,
            'The form repeated the units, once per branch. Saving is then refused with "You cannot'
                . ' assign the same unit to more than one selling unit." and the cost cannot be'
                . ' corrected at all.'
        );

        $this->assertSame(
            [ $units[ 0 ]->id, $units[ 1 ]->id ],
            $groups->map( fn ( array $group ): int => (int) $this->fieldValue( $group, 'unit_id' ) )->sort()->values()->all(),
            'the units on the form are not the units of the product'
        );

        $this->assertEqualsWithDelta(
            [ 40.0, 960.0 ],
            $groups->map( fn ( array $group ): float => (float) $this->fieldValue( $group, 'cogs' ) )->sort()->values()->all(),
            0.001,
            'The form is showing another branch\'s cost, so the figure being corrected is not the one'
                . ' the branch is trading on.'
        );
    }

    /**
     * A single store install carries no branch on any row and has nothing to scope.
     */
    public function test_a_single_store_install_still_sees_its_units(): void
    {
        [ $product, $units ] = $this->productStockedInOneBranch( withBranches: false );

        $this->attemptAuthenticate( $this->actor() );
        $this->standInBranch( null );

        $this->assertCount(
            2,
            $this->sellingGroups( $product ),
            'A shop with one branch can no longer see the units of its own products.'
        );
    }

    /**
     * The save the refusal used to block, and the branch it has to land on.
     */
    public function test_saving_the_cost_writes_it_to_the_branch_being_looked_at(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $this->attemptAuthenticate( $this->actor() );
        $this->standInBranch( $branches[ 1 ] );

        $this->submitProduct( $product, $units, cogs: 55.5, salePrice: 88 )
            ->assertStatus( 200 );

        $this->assertEqualsWithDelta(
            55.5,
            (float) $this->row( $product, $units[ 0 ], $branches[ 1 ] )->cogs,
            0.001,
            'The cost typed in was not written to the branch it was typed in.'
        );

        $this->assertEqualsWithDelta(
            88.0,
            (float) $this->row( $product, $units[ 0 ], $branches[ 1 ] )->sale_price_edit,
            0.001,
            'the sale price was not written to the branch being looked at'
        );

        $this->assertEqualsWithDelta(
            20.0,
            (float) $this->row( $product, $units[ 0 ], $branches[ 0 ] )->cogs,
            0.001,
            'Editing one branch rewrote the other branch\'s cost. A figure corrected at one shop'
                . ' changes what another shop believes it paid.'
        );

        $this->assertEqualsWithDelta(
            30.0,
            (float) $this->row( $product, $units[ 0 ], $branches[ 0 ] )->sale_price_edit,
            0.001,
            'editing one branch rewrote the other branch\'s sale price'
        );
    }

    /**
     * A branch that starts stocking an item gets a row of its own, and it says so.
     */
    public function test_a_row_created_by_a_save_carries_the_branch(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInOneBranch();

        $this->attemptAuthenticate( $this->actor() );
        $this->standInBranch( $branches[ 1 ] );

        $this->submitProduct( $product, $units, cogs: 12.5, salePrice: 19 )
            ->assertStatus( 200 );

        $created = $this->row( $product, $units[ 0 ], $branches[ 1 ] );

        $this->assertNotNull(
            $created,
            'The save created no row for the branch it was made in, so the branch holds no price at all.'
        );

        $this->assertEqualsWithDelta( 12.5, (float) $created->cogs, 0.001, 'the new row carries the wrong cost' );

        $this->assertEqualsWithDelta(
            20.0,
            (float) $this->row( $product, $units[ 0 ], $branches[ 0 ] )->cogs,
            0.001,
            'the branch that already stocked the item had its cost rewritten'
        );

        $this->assertSame(
            0,
            ProductUnitQuantity::where( 'product_id', $product->id )->whereNull( 'branch_id' )->count(),
            'A row was saved against no branch at all. Every per-branch read then treats it as'
                . ' belonging to all of them, which is how a figure entered in one branch turns up in'
                . ' another.'
        );
    }

    /**
     * Auto COGS. The delivery says which branch it was received in, and only that branch's cost moves.
     */
    public function test_a_delivery_recomputes_the_cost_of_the_branch_it_was_received_in(): void
    {
        [ $product, $units, $branches ] = $this->productStockedInTwoBranches();

        $history = $this->receiveStock( $product, $units[ 0 ], $branches[ 1 ], quantity: 10, totalPrice: 1000 );

        app( ProductService::class )->computeCogsIfNecessary( $history );

        $this->assertEqualsWithDelta(
            100.0,
            (float) $this->row( $product, $units[ 0 ], $branches[ 1 ] )->cogs,
            0.001,
            'The branch that took the delivery in still carries its old cost, so it is selling against'
                . ' a price it did not pay. On the shop this was reported from, 167 of its rows carried'
                . ' no cost at all and its sales reported a loss.'
        );

        $this->assertEqualsWithDelta(
            20.0,
            (float) $this->row( $product, $units[ 0 ], $branches[ 0 ] )->cogs,
            0.001,
            'A delivery received at one branch rewrote another branch\'s cost.'
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return \Illuminate\Support\Collection<int, array{fields: mixed}>
     */
    private function sellingGroups( Product $product ): \Illuminate\Support\Collection
    {
        $form = ( new ProductCrud )->getForm( $product );

        $field = collect( $form[ 'variations' ][ 0 ][ 'tabs' ][ 'units' ][ 'fields' ] )
            ->first( fn ( $field ): bool => ( $field[ 'name' ] ?? null ) === 'selling_group' );

        $this->assertNotNull( $field, 'the units tab no longer carries the selling unit group' );

        return collect( $field[ 'groups' ] )->map( fn ( $group ): array => (array) $group );
    }

    /**
     * @param array{fields: mixed} $group
     */
    private function fieldValue( array $group, string $name ): mixed
    {
        $field = collect( $group[ 'fields' ] )->first( fn ( $field ): bool => ( $field[ 'name' ] ?? null ) === $name );

        return $field[ 'value' ] ?? null;
    }

    /**
     * @param list<Unit> $units
     */
    private function submitProduct( Product $product, array $units, float $cogs, float $salePrice )
    {
        $payload = [
            'name' => $product->name,
            'variations' => [
                [
                    '$primary' => true,
                    'expiracy' => [
                        'expires' => 0,
                        'on_expiration' => 'prevent_sales',
                    ],
                    'identification' => [
                        'barcode' => $product->barcode,
                        'barcode_type' => 'code128',
                        'searchable' => true,
                        'category_id' => $product->category_id,
                        'description' => 'Created via tests',
                        'product_type' => 'product',
                        'type' => Product::TYPE_MATERIALIZED,
                        'sku' => $product->sku,
                        'status' => 'available',
                        'stock_management' => 'enabled',
                    ],
                    'images' => [],
                    'taxes' => [
                        'tax_group_id' => TaxGroup::firstOrFail()->id,
                        'tax_type' => 'inclusive',
                    ],
                    'units' => [
                        'unit_group' => $product->unit_group,
                        'accurate_tracking' => false,
                        'auto_cogs' => false,
                        'selling_group' => collect( $units )->map( fn ( Unit $unit ): array => [
                            'unit_id' => $unit->id,
                            'sale_price_edit' => $salePrice * (float) $unit->value,
                            'wholesale_price_edit' => $salePrice * (float) $unit->value,
                            'cogs' => $cogs * (float) $unit->value,
                        ] )->values()->toArray(),
                    ],
                ],
            ],
        ];

        return $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'PUT', '/api/products/' . $product->id, $payload );
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
     * A priced delivery, written the way the procurement writes it: the movement carries the branch.
     */
    private function receiveStock( Product $product, Unit $unit, int $branchId, float $quantity, float $totalPrice ): ProductHistory
    {
        $id = (int) DB::table( 'nexopos_products_histories' )->insertGetId( [
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'operation_type' => ProductHistory::ACTION_STOCKED,
            'before_quantity' => 0,
            'quantity' => $quantity,
            'after_quantity' => $quantity,
            'unit_price' => $totalPrice / $quantity,
            'total_price' => $totalPrice,
            'author_id' => $this->actor()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_products_histories', $id ];

        return ProductHistory::findOrFail( $id );
    }

    /**
     * Two branches, each with its own cost and its own price for the same two units. The unit values
     * are 1 and 24, so a cost per piece and a cost per case are the same fact stated twice.
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
                    salePrice: ( $index === 0 ? 30.0 : 50.0 ) * (float) $unit->value
                );
            }
        }

        return [ $product, $units, $branches ];
    }

    /**
     * The item is stocked in the first branch only. The second one is where the save is made from.
     *
     * @return array{0: Product, 1: list<Unit>, 2: list<int>}
     */
    private function productStockedInOneBranch( bool $withBranches = true ): array
    {
        $group = $this->unitGroup();
        $units = [ $this->unit( $group->id, 1 ), $this->unit( $group->id, 24 ) ];
        $product = $this->product( $group->id );

        $branches = $withBranches
            ? [ $this->makeBranch( 'สาขาต้นทุนเดียว' ), $this->makeBranch( 'สาขาต้นทุนใหม่' ) ]
            : [ null, null ];

        foreach ( $units as $unit ) {
            $this->unitQuantity(
                $product->id,
                $unit->id,
                $branches[ 0 ],
                cogs: 20.0 * (float) $unit->value,
                salePrice: 30.0 * (float) $unit->value
            );
        }

        return [ $product, $units, $branches ];
    }

    private function makeBranch( string $name ): int
    {
        $id = (int) DB::table( 'nexopos_branches' )->insertGetId( [
            'code' => 'COGS' . random_int( 100000, 999999 ),
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
        $group->name = 'ต้นทุนรายสาขา ' . random_int( 100000, 999999 );
        $group->author_id = 1;
        $group->save();

        $this->createdRows[] = [ 'nexopos_units_groups', $group->id ];

        return $group;
    }

    private function unit( int $groupId, float $value ): Unit
    {
        $unit = new Unit;
        $unit->name = ( $value === 1.0 ? 'ชิ้นต้นทุน ' : 'ลังต้นทุน ' ) . random_int( 100000, 999999 );
        $unit->identifier = 'cogs-branch-' . random_int( 100000, 999999 );
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
        $product->name = 'สินค้าทดสอบต้นทุน ' . $suffix;
        $product->barcode = 'COGS' . $suffix;
        $product->sku = 'cogs-' . Str::random( 8 ) . '-' . $suffix;
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

    private function unitQuantity( int $productId, int $unitId, ?int $branchId, float $cogs, float $salePrice ): void
    {
        $row = new ProductUnitQuantity;
        $row->product_id = $productId;
        $row->unit_id = $unitId;
        $row->branch_id = $branchId;
        $row->quantity = 0;
        $row->barcode = 'COGSQ' . random_int( 100000, 999999 );
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

    /**
     * The branch the person at the keyboard is standing in, set the way a real request sets it: the
     * administrator has no branch of their own and picks one at the top of the screen.
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
