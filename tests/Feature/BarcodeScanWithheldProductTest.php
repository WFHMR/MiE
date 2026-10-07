<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * A product is withheld from sale exactly when the supplier file gave it no price. The barcode lookup
 * used to apply that restriction only when the scanned code belonged to the product, not when it
 * belonged to one of its unit rows. Since the importer writes the barcode on the unit row, every
 * unpriced item was reachable that way and the till added it to the cart at zero.
 */
class BarcodeScanWithheldProductTest extends TestCase
{
    use DatabaseTransactions;
    use WithAuthentication;

    /**
     * @return array{0: Product, 1: ProductUnitQuantity}
     */
    private function makeProduct( string $status, float $price ): array
    {
        $group = new UnitGroup;
        $group->name = 'scan-' . uniqid();
        $group->author_id = 1;
        $group->save();

        $unit = new Unit;
        $unit->name = 'ถุง';
        $unit->identifier = 'scan-' . uniqid();
        $unit->group_id = $group->id;
        $unit->value = 1;
        $unit->base_unit = true;
        $unit->author_id = 1;
        $unit->save();

        $barcode = 'SCAN' . uniqid();

        $product = new Product;
        $product->name = 'สินค้าทดสอบการยิง';
        $product->barcode = $barcode;
        $product->sku = $barcode;
        $product->barcode_type = 'code128';
        $product->unit_group = $group->id;
        $product->author_id = 1;
        $product->type = Product::TYPE_MATERIALIZED;
        $product->product_type = 'product';
        $product->status = $status;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->accurate_tracking = false;
        $product->tax_type = 'inclusive';
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->searchable = true;
        $product->save();

        $row = new ProductUnitQuantity;
        $row->product_id = $product->id;
        $row->unit_id = $unit->id;
        $row->barcode = $barcode . '-U';
        $row->quantity = 10;
        $row->sale_price = $price;
        $row->sale_price_edit = $price;
        $row->cogs = 0;
        $row->type = 'product';
        $row->visible = true;
        $row->save();

        return [ $product, $row ];
    }

    public function test_a_unit_barcode_of_a_withheld_product_is_not_found(): void
    {
        $this->attemptAuthenticate();

        [ , $row ] = $this->makeProduct( Product::STATUS_UNAVAILABLE, 0.0 );

        $response = $this->json( 'GET', '/api/products/search/using-barcode/' . $row->barcode );

        $response->assertStatus( 404 );
    }

    public function test_a_unit_barcode_of_a_product_on_sale_still_resolves(): void
    {
        $this->attemptAuthenticate();

        [ , $row ] = $this->makeProduct( Product::STATUS_AVAILABLE, 42.0 );

        $response = $this->json( 'GET', '/api/products/search/using-barcode/' . $row->barcode );

        $response->assertStatus( 200 );

        /**
         * The scan must come back on the scanned unit and at that unit's price, which is the whole
         * point of writing a barcode per unit.
         */
        $this->assertSame( 'ถุง', $response->json( 'unit.name' ) );
        $this->assertEqualsWithDelta( 42.0, (float) $response->json( 'unitQuantity.sale_price' ), 0.001 );
    }
}
