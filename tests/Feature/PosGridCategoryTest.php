<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class PosGridCategoryTest extends TestCase
{
    use WithAuthentication;

    /**
     * Returns the category holding the most products that the POS can display.
     */
    private function getBusiestCategory(): ?ProductCategory
    {
        return ProductCategory::where( 'displays_on_pos', true )
            ->withCount( 'products' )
            ->orderBy( 'products_count', 'desc' )
            ->first();
    }

    public function test_pos_categories_endpoint_returns_pagination_metadata(): void
    {
        $this->attemptAuthenticate();

        $category = $this->getBusiestCategory();

        $this->assertInstanceOf( ProductCategory::class, $category, 'No POS category is available on the test database.' );

        $response = $this->json( 'GET', '/api/categories/pos/' . $category->id );

        $response->assertStatus( 200 );
        $response->assertJsonStructure( [
            'products',
            'productsPagination' => [
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
            'categories',
            'currentCategory',
            'pinnedProducts',
        ] );

        $pagination = $response->json( 'productsPagination' );

        $this->assertSame( 1, $pagination[ 'current_page' ] );
        $this->assertGreaterThan( 0, $pagination[ 'per_page' ] );
        $this->assertLessThanOrEqual(
            $pagination[ 'per_page' ],
            count( $response->json( 'products' ) ),
            'The endpoint returned more products than a single page allows.'
        );
    }

    public function test_pos_categories_endpoint_does_not_scale_queries_with_the_product_count(): void
    {
        $this->attemptAuthenticate();

        $category = $this->getBusiestCategory();

        $this->assertInstanceOf( ProductCategory::class, $category, 'No POS category is available on the test database.' );

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->json( 'GET', '/api/categories/pos/' . $category->id );

        $queries = count( DB::getQueryLog() );

        DB::disableQueryLog();

        $response->assertStatus( 200 );

        $returned = count( $response->json( 'products' ) );

        /**
         * Resolving the sellable units product by product used to cost two
         * extra queries each. The whole response should now stay well under
         * one query per product.
         */
        $this->assertLessThan(
            30,
            $queries,
            sprintf( 'The endpoint ran %d queries for %d products, which suggests a N+1.', $queries, $returned )
        );
    }

    public function test_pos_categories_endpoint_exposes_the_sellable_units_of_each_product(): void
    {
        $this->attemptAuthenticate();

        $category = $this->getBusiestCategory();

        $this->assertInstanceOf( ProductCategory::class, $category, 'No POS category is available on the test database.' );

        $response = $this->json( 'GET', '/api/categories/pos/' . $category->id );

        $response->assertStatus( 200 );

        $products = $response->json( 'products' );

        if ( count( $products ) === 0 ) {
            $this->markTestSkipped( 'The busiest POS category holds no sellable product.' );
        }

        /**
         * The grid reads the price out of unit_quantities, so the relation has
         * to travel with every product.
         */
        foreach ( $products as $product ) {
            $this->assertArrayHasKey(
                'unit_quantities',
                $product,
                'A product was returned without its unit quantities.'
            );
        }
    }

    public function test_pos_categories_endpoint_walks_through_every_page(): void
    {
        $this->attemptAuthenticate();

        $category = $this->getBusiestCategory();

        $this->assertInstanceOf( ProductCategory::class, $category, 'No POS category is available on the test database.' );

        $firstPage = $this->json( 'GET', '/api/categories/pos/' . $category->id );
        $firstPage->assertStatus( 200 );

        $pagination = $firstPage->json( 'productsPagination' );

        if ( $pagination[ 'last_page' ] < 2 ) {
            $this->markTestSkipped( 'The busiest POS category fits on a single page.' );
        }

        $secondPage = $this->json( 'GET', '/api/categories/pos/' . $category->id . '?page=2' );
        $secondPage->assertStatus( 200 );

        $this->assertSame( 2, $secondPage->json( 'productsPagination.current_page' ) );

        $firstIds = collect( $firstPage->json( 'products' ) )->pluck( 'id' );
        $secondIds = collect( $secondPage->json( 'products' ) )->pluck( 'id' );

        $this->assertGreaterThan( 0, $secondIds->count(), 'The second page came back empty.' );
        $this->assertCount(
            0,
            $firstIds->intersect( $secondIds ),
            'The same products were served on two different pages.'
        );
    }

    public function test_pos_categories_root_returns_the_categories_displayed_on_pos(): void
    {
        $this->attemptAuthenticate();

        $response = $this->json( 'GET', '/api/categories/pos/' );

        $response->assertStatus( 200 );

        $expected = ProductCategory::where( 'displays_on_pos', true )
            ->where( function ( $query ) {
                $query->whereNull( 'parent_id' )->orWhere( 'parent_id', 0 );
            } )
            ->count();

        $this->assertLessThanOrEqual(
            $expected,
            count( $response->json( 'categories' ) ),
            'The root listing returned categories that are not meant for the POS.'
        );

        $this->assertSame( [], $response->json( 'products' ) );
    }

    public function test_products_shown_on_pos_are_available_and_not_accurately_tracked(): void
    {
        $this->attemptAuthenticate();

        $category = $this->getBusiestCategory();

        $this->assertInstanceOf( ProductCategory::class, $category, 'No POS category is available on the test database.' );

        $response = $this->json( 'GET', '/api/categories/pos/' . $category->id );
        $response->assertStatus( 200 );

        foreach ( $response->json( 'products' ) as $product ) {
            $this->assertSame( Product::STATUS_AVAILABLE, $product[ 'status' ] );
            $this->assertFalse( (bool) $product[ 'accurate_tracking' ] );
        }
    }
}
