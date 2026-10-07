<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Hiding empty products is what keeps a cashier from building a cart that cannot
 * be paid for, so the command has to be exact in both directions: an empty product
 * must leave the till, and a product holding stock must never be taken off it.
 *
 * The POS grid and the barcode lookup both filter through Product::onSale(), so
 * status is the field that decides whether a product can reach a cart at all.
 */
class HideOutOfStockProductsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_hides_products_without_stock(): void
    {
        $empty = $this->productWithQuantity( 0 );

        $this->artisan( 'stock:hide-out-of-stock', [ '--samples' => 0 ] )->assertSuccessful();

        $hidden = $empty->fresh();

        $this->assertSame( Product::STATUS_UNAVAILABLE, $hidden->status );
        $this->assertFalse( (bool) $hidden->searchable );

        /**
         * onSale() is the scope the POS grid and the barcode lookup use, so this is
         * the assertion that proves the product cannot reach a cart.
         */
        $this->assertFalse(
            Product::onSale()->where( 'id', $empty->id )->exists(),
            'A hidden product must not be returned by the scope the POS grid uses.'
        );
    }

    public function test_it_leaves_products_holding_stock_on_sale(): void
    {
        $stocked = $this->productWithQuantity( 12 );

        $this->artisan( 'stock:hide-out-of-stock', [ '--samples' => 0 ] )->assertSuccessful();

        $this->assertSame( Product::STATUS_AVAILABLE, $stocked->fresh()->status );
        $this->assertTrue( Product::onSale()->where( 'id', $stocked->id )->exists() );
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $empty = $this->productWithQuantity( 0 );

        $this->artisan( 'stock:hide-out-of-stock', [ '--dry-run' => true, '--samples' => 0 ] )
            ->assertSuccessful();

        $this->assertSame( Product::STATUS_AVAILABLE, $empty->fresh()->status );
    }

    /**
     * A product with one empty unit row and one holding stock is still sellable, so
     * it has to stay on the till. Summing quantities would get this right by
     * accident; a negative row plus a positive one would not.
     */
    public function test_a_product_with_any_stocked_unit_stays_on_sale(): void
    {
        $product = $this->productWithQuantity( -5 );

        ProductUnitQuantity::factory()->create( [
            'product_id' => $product->id,
            'unit_id' => $this->anotherUnitId(),
            'quantity' => 30,
        ] );

        $this->artisan( 'stock:hide-out-of-stock', [ '--samples' => 0 ] )->assertSuccessful();

        $this->assertSame( Product::STATUS_AVAILABLE, $product->fresh()->status );
    }

    public function test_restore_only_brings_back_products_that_hold_stock(): void
    {
        $empty = $this->productWithQuantity( 0 );
        $refilled = $this->productWithQuantity( 0 );

        $this->artisan( 'stock:hide-out-of-stock', [ '--samples' => 0 ] )->assertSuccessful();

        ProductUnitQuantity::where( 'product_id', $refilled->id )->update( [ 'quantity' => 25 ] );

        $this->artisan( 'stock:hide-out-of-stock', [ '--restore' => true ] )->assertSuccessful();

        $this->assertSame(
            Product::STATUS_AVAILABLE,
            $refilled->fresh()->status,
            'A product that received stock must come back on sale.'
        );

        $this->assertSame(
            Product::STATUS_UNAVAILABLE,
            $empty->fresh()->status,
            'A product that is still empty must stay off the till.'
        );
    }

    private function productWithQuantity( float $quantity ): Product
    {
        $product = Product::factory()->create( [
            'status' => Product::STATUS_AVAILABLE,
            'searchable' => true,
        ] );

        ProductUnitQuantity::factory()->create( [
            'product_id' => $product->id,
            'unit_id' => $this->unitId(),
            'quantity' => $quantity,
        ] );

        return $product;
    }

    private function unitId(): int
    {
        return (int) Unit::firstOrFail()->id;
    }

    private function anotherUnitId(): int
    {
        return (int) Unit::where( 'id', '!=', $this->unitId() )->firstOrFail()->id;
    }
}
