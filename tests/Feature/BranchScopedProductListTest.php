<?php

namespace Tests\Feature;

use App\Crud\ProductCrud;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\CrudEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The products list is the page staff check stock and prices on, so it has to agree with the till.
 *
 * The branch it displayed was read behind the ns_store_enabled option, which does not exist in this
 * database and so reads as off. The branch was therefore always null and the list stacked every
 * branch's rows together: two prices and two on-hand figures for the same unit of the same product,
 * with nothing to say which shelf either belonged to. A branch cashier was reading another branch's
 * numbers.
 *
 * A single store install has no branch on any of its rows, and must keep showing all of them, so the
 * scoping has to let branchless rows through.
 */
class BranchScopedProductListTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_branch_user_sees_only_their_own_branch_figures(): void
    {
        [ $product, $branches ] = $this->productPricedDifferentlyInThreeBranches();

        foreach ( $branches as $index => $branchId ) {
            session( [ 'selected_branch_id' => $branchId ] );

            $units = $this->describeUnits( $product );

            $this->assertCount(
                1,
                $units,
                'One branch, one row per unit. More than one means another branch leaked in.'
            );

            $this->assertSame(
                [ 100.0, 200.0, 300.0 ][ $index ],
                (float) $units[ 0 ][ 'stock' ],
                'The list showed a stock figure belonging to another branch.'
            );

            $this->assertStringContainsString(
                [ '11', '22', '33' ][ $index ],
                (string) $units[ 0 ][ 'price' ],
                'The list showed a price belonging to another branch.'
            );
        }

        session()->forget( 'selected_branch_id' );
    }

    /**
     * The barcode column has the same problem: the same barcode exists once per branch, so an
     * unscoped list printed all of them under one product.
     */
    public function test_the_barcode_column_shows_only_this_branch_barcode(): void
    {
        [ $product, $branches ] = $this->productPricedDifferentlyInThreeBranches();

        session( [ 'selected_branch_id' => $branches[ 1 ] ] );

        $units = $this->describeUnits( $product );

        session()->forget( 'selected_branch_id' );

        $this->assertCount( 1, $units );
        $this->assertSame( 'BR2CODE', $units[ 0 ][ 'barcode' ] );
    }

    /**
     * The regression guard. A single store install carries no branch on its rows, and the resolver
     * still offers a branch from the session, so branchless rows have to be let through or the
     * column empties on every product in the catalogue.
     */
    public function test_a_branchless_row_is_still_shown(): void
    {
        $group = $this->unitGroup();
        $unit = $this->unit( $group->id, 'ชิ้นไร้สาขา' );
        $product = $this->product( $group->id );

        $this->unitQuantity( $product->id, $unit->id, branchId: null, quantity: 55, price: 44, barcode: 'NOBRANCH' );

        session( [ 'selected_branch_id' => 987654 ] );

        $units = $this->describeUnits( $product );

        session()->forget( 'selected_branch_id' );

        $this->assertCount( 1, $units, 'A branchless row disappeared, which would empty a single store catalogue.' );
        $this->assertSame( 55.0, (float) $units[ 0 ][ 'stock' ] );
    }

    // ------------------------------------------------------------------ fixture

    /**
     * @return array<int, array{name: string, price: string, stock: float, ratio: float, converted: bool, barcode: ?string}>
     */
    private function describeUnits( Product $product ): array
    {
        /**
         * A fresh CRUD each time: it caches what it has already described, per request, keyed by
         * product id, so reusing one would answer the second branch with the first branch's rows.
         */
        $crud = new ProductCrud;

        $entry = new CrudEntry( [
            'id' => $product->id,
            'stock_on_base_unit' => $product->stock_on_base_unit,
        ] );

        $method = new \ReflectionMethod( ProductCrud::class, 'describeUnits' );

        return array_values( $method->invoke( $crud, $entry ) );
    }

    /**
     * @return array{0: Product, 1: list<int>}
     */
    private function productPricedDifferentlyInThreeBranches(): array
    {
        $branches = [
            $this->makeBranch( 'สาขารายการหนึ่ง' ),
            $this->makeBranch( 'สาขารายการสอง' ),
            $this->makeBranch( 'สาขารายการสาม' ),
        ];

        $group = $this->unitGroup();
        $unit = $this->unit( $group->id, 'ชิ้นรายการ' );
        $product = $this->product( $group->id );

        $this->unitQuantity( $product->id, $unit->id, $branches[ 0 ], 100, 11, 'BR1CODE' );
        $this->unitQuantity( $product->id, $unit->id, $branches[ 1 ], 200, 22, 'BR2CODE' );
        $this->unitQuantity( $product->id, $unit->id, $branches[ 2 ], 300, 33, 'BR3CODE' );

        return [ $product, $branches ];
    }

    private function makeBranch( string $name ): int
    {
        return (int) DB::table( 'nexopos_branches' )->insertGetId( [
            'code' => 'PL' . uniqid(),
            'name' => $name,
            'is_main' => false,
            'is_active' => true,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );
    }

    private function unitGroup(): UnitGroup
    {
        $group = new UnitGroup;
        $group->name = 'pl-' . uniqid();
        $group->author_id = 1;
        $group->save();

        return $group;
    }

    private function unit( int $groupId, string $name ): Unit
    {
        $unit = new Unit;
        $unit->name = $name . '-' . uniqid();
        $unit->identifier = 'pl-' . uniqid();
        $unit->group_id = $groupId;
        $unit->value = 1;
        $unit->base_unit = true;
        $unit->author_id = 1;
        $unit->save();

        return $unit;
    }

    private function product( int $unitGroupId ): Product
    {
        $product = new Product;
        $product->stock_on_base_unit = false;
        $product->name = 'สินค้าทดสอบรายการ ' . uniqid();
        $product->barcode = 'PL' . uniqid();
        $product->sku = $product->barcode;
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

        return $product;
    }

    private function unitQuantity( int $productId, int $unitId, ?int $branchId, float $quantity, float $price, string $barcode ): void
    {
        $row = new ProductUnitQuantity;
        $row->product_id = $productId;
        $row->unit_id = $unitId;
        $row->branch_id = $branchId;
        $row->quantity = $quantity;
        $row->barcode = $barcode;
        $row->sale_price = $price;
        $row->sale_price_edit = $price;
        $row->wholesale_price = $price;
        $row->cogs = $price;
        $row->type = 'product';
        $row->visible = true;
        $row->save();
    }
}
