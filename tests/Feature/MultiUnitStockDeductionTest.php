<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A wholesaler receives cartons and sells both cartons and single items from the same shelf,
 * scanning whichever barcode is in front of the cashier. Holding a separate stock figure per
 * unit lets the same goods be sold twice, once as a carton and again as its contents, so the
 * figure lives on one unit and every other unit is a label carrying its own barcode and
 * price. Selling through a label has to reach the figure.
 *
 * The risk in arranging that is the ordinary product: the overwhelming majority of the
 * catalogue has a single unit and must keep deducting exactly as it did. These tests pin both
 * halves, starting with the half that must not change.
 */
class MultiUnitStockDeductionTest extends TestCase
{
    use DatabaseTransactions;

    private ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productService = app( ProductService::class );
    }

    public function test_single_unit_product_is_left_untouched(): void
    {
        [ $product, $unit ] = $this->makeSingleUnitProduct();

        $resolved = $this->productService->resolveStockBearingUnit( $product->id, $unit->id );

        $this->assertSame(
            $unit->id,
            $resolved[ 'unit_id' ],
            'A product with one unit must resolve to that same unit, otherwise every existing product changes behaviour.'
        );

        $this->assertSame(
            1.0,
            $resolved[ 'factor' ],
            'A single unit product must not have its quantities scaled.'
        );
    }

    public function test_unit_that_is_its_own_base_is_left_untouched(): void
    {
        [ $product, $base, $carton ] = $this->makeCartonAndPieceProduct();

        $resolved = $this->productService->resolveStockBearingUnit( $product->id, $base->id );

        $this->assertSame( $base->id, $resolved[ 'unit_id' ] );
        $this->assertSame( 1.0, $resolved[ 'factor' ] );

        // Sanity: the carton is genuinely a different unit in the same group.
        $this->assertNotSame( $base->id, $carton->id );
        $this->assertSame( $base->group_id, $carton->group_id );
    }

    public function test_selling_through_a_carton_resolves_to_the_piece_unit(): void
    {
        [ $product, $base, $carton ] = $this->makeCartonAndPieceProduct();

        $resolved = $this->productService->resolveStockBearingUnit( $product->id, $carton->id );

        $this->assertSame(
            $base->id,
            $resolved[ 'unit_id' ],
            'A carton sale has to be measured against the piece count that actually exists.'
        );

        $this->assertSame(
            24.0,
            $resolved[ 'factor' ],
            'One carton of twenty four has to weigh twenty four pieces.'
        );
    }

    public function test_carton_without_a_piece_row_is_left_untouched(): void
    {
        [ $product, $base, $carton ] = $this->makeCartonAndPieceProduct();

        /**
         * Removing the piece row leaves the carton as the only place stock could live. The
         * deduction must stay on the carton rather than be redirected to a row that is not
         * there, which would silently lose the movement.
         */
        ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $base->id )
            ->delete();

        $resolved = $this->productService->resolveStockBearingUnit( $product->id, $carton->id );

        $this->assertSame( $carton->id, $resolved[ 'unit_id' ] );
        $this->assertSame( 1.0, $resolved[ 'factor' ] );
    }

    public function test_unknown_unit_is_left_untouched(): void
    {
        [ $product ] = $this->makeSingleUnitProduct();

        $resolved = $this->productService->resolveStockBearingUnit( $product->id, 0 );

        $this->assertSame( 0, $resolved[ 'unit_id' ] );
        $this->assertSame( 1.0, $resolved[ 'factor' ] );
    }

    /**
     * @return array{0: Product, 1: Unit}
     */
    private function makeSingleUnitProduct(): array
    {
        $group = new UnitGroup;
        $group->name = 'test-single-' . uniqid();
        $group->author_id = 1;
        $group->save();

        $unit = new Unit;
        $unit->name = 'ชิ้นทดสอบ';
        $unit->identifier = 'test-piece-' . uniqid();
        $unit->group_id = $group->id;
        $unit->value = 1;
        $unit->base_unit = true;
        $unit->author_id = 1;
        $unit->save();

        $product = $this->makeProduct( $group->id );

        $this->makeUnitQuantity( $product->id, $unit->id, quantity: 10, price: 20 );

        return [ $product, $unit ];
    }

    /**
     * A piece priced at twenty and a carton of twenty four priced at four hundred, which is
     * the shape the shop's own catalogue uses.
     *
     * @return array{0: Product, 1: Unit, 2: Unit}
     */
    private function makeCartonAndPieceProduct(): array
    {
        $group = new UnitGroup;
        $group->name = 'test-carton-' . uniqid();
        $group->author_id = 1;
        $group->save();

        $base = new Unit;
        $base->name = 'ขวดทดสอบ';
        $base->identifier = 'test-bottle-' . uniqid();
        $base->group_id = $group->id;
        $base->value = 1;
        $base->base_unit = true;
        $base->author_id = 1;
        $base->save();

        $carton = new Unit;
        $carton->name = 'หีบทดสอบ';
        $carton->identifier = 'test-carton-' . uniqid();
        $carton->group_id = $group->id;
        $carton->value = 24;
        $carton->base_unit = false;
        $carton->author_id = 1;
        $carton->save();

        $product = $this->makeProduct( $group->id );

        $this->makeUnitQuantity( $product->id, $base->id, quantity: 48, price: 20 );
        $this->makeUnitQuantity( $product->id, $carton->id, quantity: 0, price: 400 );

        return [ $product, $base, $carton ];
    }

    private function makeProduct( int $unitGroupId ): Product
    {
        $product = new Product;
        $product->name = 'สินค้าทดสอบ ' . uniqid();
        $product->barcode = 'T' . uniqid();
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

    private function makeUnitQuantity( int $productId, int $unitId, float $quantity, float $price ): ProductUnitQuantity
    {
        $unitQuantity = new ProductUnitQuantity;
        $unitQuantity->product_id = $productId;
        $unitQuantity->unit_id = $unitId;
        $unitQuantity->quantity = $quantity;
        $unitQuantity->sale_price = $price;
        $unitQuantity->sale_price_edit = $price;
        $unitQuantity->wholesale_price = $price;
        $unitQuantity->cogs = $price;
        $unitQuantity->type = 'product';
        $unitQuantity->visible = true;
        $unitQuantity->save();

        return $unitQuantity;
    }
}
