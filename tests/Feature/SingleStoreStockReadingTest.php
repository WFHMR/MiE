<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * On a single store install every sale was refused with "this action will cause
 * negative stock (-1). Old Quantity : (0)", including products holding thousands
 * of units.
 *
 * SetDefaultBranch puts a branch in the session for every authenticated user
 * regardless of whether multi store is switched on, and ProductService read stock
 * filtered by that branch. Single store stock rows carry no branch, so the filter
 * matched nothing and getQuantity() returned zero. The order passed the check in
 * OrdersService, which reads the unit quantity row directly and does not filter by
 * branch, and was then rejected during the stock adjustment.
 *
 * The two readings have to agree, so branch filtering only applies when the install
 * is actually multi store.
 */
class SingleStoreStockReadingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_stock_is_readable_when_a_branch_is_in_the_session_but_multi_store_is_off(): void
    {
        ns()->option->set( 'ns_store_enabled', 'no' );

        /**
         * Reproduces what SetDefaultBranch leaves behind on a normal login.
         */
        session( [ 'selected_branch_id' => 1 ] );

        [ $product, $unitId ] = $this->productHoldingStock( 250 );

        $quantity = app( ProductService::class )->getQuantity( $product->id, $unitId );

        $this->assertEquals(
            250,
            $quantity,
            'A single store install must report the stock it holds, whatever branch the session carries.'
        );
    }

    public function test_stock_reading_matches_the_unit_quantity_row(): void
    {
        ns()->option->set( 'ns_store_enabled', 'no' );
        session( [ 'selected_branch_id' => 7 ] );

        [ $product, $unitId ] = $this->productHoldingStock( 12 );

        /**
         * OrdersService validates against this row, ProductService against
         * getQuantity(). A sale only completes when both see the same number.
         */
        $rowQuantity = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $unitId )
            ->value( 'quantity' );

        $serviceQuantity = app( ProductService::class )->getQuantity( $product->id, $unitId );

        $this->assertEquals( $rowQuantity, $serviceQuantity );
    }

    public function test_branch_filtering_still_applies_when_multi_store_is_on(): void
    {
        if ( ! Schema::hasColumn( 'nexopos_products_unit_quantities', 'branch_id' ) ) {
            $this->markTestSkipped( 'This install has no branch_id column, so branch filtering cannot apply.' );
        }

        ns()->option->set( 'ns_store_enabled', 'yes' );
        session( [ 'selected_branch_id' => 999 ] );

        [ $product, $unitId ] = $this->productHoldingStock( 40 );

        /**
         * The row belongs to no branch, so a multi store install looking at branch
         * 999 must not count it. Losing this would let one branch sell another
         * branch's stock.
         */
        $quantity = app( ProductService::class )->getQuantity( $product->id, $unitId );

        $this->assertEquals( 0, $quantity );

        ns()->option->set( 'ns_store_enabled', 'no' );
    }

    /**
     * @return array{0: Product, 1: int}
     */
    private function productHoldingStock( float $quantity ): array
    {
        $product = Product::factory()->create( [
            'stock_management' => Product::STOCK_MANAGEMENT_ENABLED,
            'status' => Product::STATUS_AVAILABLE,
        ] );

        $unitId = (int) Unit::firstOrFail()->id;

        $attributes = [
            'product_id' => $product->id,
            'unit_id' => $unitId,
            'quantity' => $quantity,
        ];

        /**
         * branch_id only exists on installs that have run the multi store
         * migration, so it is set only when the column is there.
         */
        if ( Schema::hasColumn( 'nexopos_products_unit_quantities', 'branch_id' ) ) {
            $attributes[ 'branch_id' ] = null;
        }

        ProductUnitQuantity::factory()->create( $attributes );

        return [ $product, $unitId ];
    }
}
