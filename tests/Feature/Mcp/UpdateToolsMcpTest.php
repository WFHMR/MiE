<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\BulkUpdateProductsTool;
use App\Mcp\Tools\UpdateCategoryTool;
use App\Mcp\Tools\UpdateProductTool;
use App\Mcp\Tools\UpdateProductUnitQuantityTool;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnitQuantity;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\TestsMcpTools;

/**
 * Covers the MCP write tools that mutate the catalogue. These had no coverage,
 * which is how three of them shipped with an undeclared "id" parameter.
 */
class UpdateToolsMcpTest extends TestCase
{
    use DatabaseTransactions, TestsMcpTools;

    private function makeProduct(): Product
    {
        TaxGroup::factory()->create();
        UnitGroup::factory()->create();

        return Product::factory()->create();
    }

    public function test_update_product_changes_the_requested_fields(): void
    {
        $product = $this->makeProduct();

        $response = $this->runMcpTool( UpdateProductTool::class, [
            'id' => $product->id,
            'name' => 'Renamed By Mcp',
        ] );

        $this->assertIsArray( $response );
        $this->assertArrayNotHasKey( 'error', $response );
        $this->assertSame( 'Renamed By Mcp', $response['name'] );
        $this->assertSame( 'Renamed By Mcp', $product->fresh()->name );
    }

    public function test_update_product_requires_an_id(): void
    {
        $response = $this->runMcpTool( UpdateProductTool::class, [
            'name' => 'No Id Provided',
        ] );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'error', $response );
        $this->assertSame( 'The id parameter is required.', $response['message'] );
    }

    public function test_update_product_reports_a_missing_product(): void
    {
        $response = $this->runMcpTool( UpdateProductTool::class, [
            'id' => 99999999,
            'name' => 'Nope',
        ] );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'error', $response );
        $this->assertSame( 'Product not found.', $response['message'] );
    }

    public function test_update_category_changes_the_requested_fields(): void
    {
        $category = ProductCategory::factory()->create();

        $response = $this->runMcpTool( UpdateCategoryTool::class, [
            'id' => $category->id,
            'name' => 'Renamed Category',
        ] );

        $this->assertIsArray( $response );
        $this->assertArrayNotHasKey( 'error', $response );
        $this->assertSame( 'Renamed Category', $category->fresh()->name );
    }

    public function test_update_category_requires_an_id(): void
    {
        $response = $this->runMcpTool( UpdateCategoryTool::class, [
            'name' => 'No Id Provided',
        ] );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'error', $response );
        $this->assertSame( 'The id parameter is required.', $response['message'] );
    }

    public function test_update_product_unit_quantity_changes_pricing(): void
    {
        $product = $this->makeProduct();

        /**
         * Built explicitly rather than through UnitFactory, which omits the
         * NOT NULL "identifier" column. unit_id is likewise required on the
         * unit quantity table and left to the caller, as ProductsSeeder does.
         */
        $unit = Unit::create( [
            'name' => 'Mcp Test Unit',
            'identifier' => 'mcp-test-unit-' . uniqid(),
            'author_id' => User::first()->id,
            'group_id' => UnitGroup::first()->id,
            'value' => 1,
            'base_unit' => true,
        ] );

        $unitQuantity = ProductUnitQuantity::factory()->create( [
            'product_id' => $product->id,
            'unit_id' => $unit->id,
        ] );

        $response = $this->runMcpTool( UpdateProductUnitQuantityTool::class, [
            'id' => $unitQuantity->id,
            'sale_price' => 199.5,
        ] );

        $this->assertIsArray( $response );
        $this->assertArrayNotHasKey( 'error', $response );
        $this->assertEquals( 199.5, $unitQuantity->fresh()->sale_price );
    }

    public function test_update_product_unit_quantity_requires_an_id(): void
    {
        $response = $this->runMcpTool( UpdateProductUnitQuantityTool::class, [
            'sale_price' => 10,
        ] );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'error', $response );
        $this->assertSame( 'The id parameter is required.', $response['message'] );
    }

    public function test_bulk_update_products_applies_the_field_to_every_id(): void
    {
        $first = $this->makeProduct();
        $second = Product::factory()->create();

        $response = $this->runMcpTool( BulkUpdateProductsTool::class, [
            'ids' => [ $first->id, $second->id ],
            'status' => 'unavailable',
        ] );

        $this->assertIsArray( $response );
        $this->assertArrayNotHasKey( 'error', $response );
        $this->assertSame( 2, $response['updated_count'] );
        $this->assertSame( [ 'status' ], $response['applied_fields'] );
        $this->assertSame( 'unavailable', $first->fresh()->status );
        $this->assertSame( 'unavailable', $second->fresh()->status );
    }

    public function test_bulk_update_products_reports_ids_that_do_not_exist(): void
    {
        $product = $this->makeProduct();

        $response = $this->runMcpTool( BulkUpdateProductsTool::class, [
            'ids' => [ $product->id, 99999999 ],
            'status' => 'unavailable',
        ] );

        $this->assertSame( 1, $response['updated_count'] );
        $this->assertSame( [ 99999999 ], $response['not_found_ids'] );
    }

    public function test_bulk_update_products_rejects_an_empty_id_list(): void
    {
        $response = $this->runMcpTool( BulkUpdateProductsTool::class, [
            'ids' => [],
            'status' => 'unavailable',
        ] );

        $this->assertArrayHasKey( 'error', $response );
        $this->assertStringContainsString( 'non-empty array', $response['message'] );
    }

    public function test_bulk_update_products_rejects_a_request_without_updatable_fields(): void
    {
        $product = $this->makeProduct();

        $response = $this->runMcpTool( BulkUpdateProductsTool::class, [
            'ids' => [ $product->id ],
        ] );

        $this->assertArrayHasKey( 'error', $response );
        $this->assertStringContainsString( 'No valid update fields provided', $response['message'] );
    }

    public function test_bulk_update_products_refuses_an_oversized_batch(): void
    {
        $ids = range( 1, BulkUpdateProductsTool::MAX_IDS + 1 );

        $response = $this->runMcpTool( BulkUpdateProductsTool::class, [
            'ids' => $ids,
            'status' => 'unavailable',
        ] );

        $this->assertArrayHasKey( 'error', $response );
        $this->assertStringContainsString( 'Too many products requested', $response['message'] );
    }
}
