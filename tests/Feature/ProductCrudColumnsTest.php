<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Covers the product list at dashboard/products: the unit, price, remaining stock and
 * barcode cells, and the join defect that kept most of the catalogue off the page.
 */
class ProductCrudColumnsTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    /**
     * A product that carries at least one unit row, which is what the three new cells
     * are built from.
     */
    private function getProductWithUnitQuantities(): ?Product
    {
        $productId = ProductUnitQuantity::query()
            ->whereNotNull( 'unit_id' )
            ->value( 'product_id' );

        return $productId === null ? null : Product::find( $productId );
    }

    /**
     * Locates one product inside a list response, whatever page shape it comes back in.
     */
    private function findEntry( array $entries, int $productId ): ?array
    {
        foreach ( $entries as $entry ) {
            if ( (int) ( $entry[ 'id' ] ?? 0 ) === $productId ) {
                return $entry;
            }
        }

        return null;
    }

    public function test_the_list_carries_the_unit_price_stock_and_barcode_cells(): void
    {
        $this->attemptAuthenticate();

        $product = $this->getProductWithUnitQuantities();

        $this->assertInstanceOf( Product::class, $product, 'No product on the test database holds a unit row.' );

        $response = $this->json( 'GET', 'api/crud/ns.products?per_page=200&search=' . urlencode( $product->name ) );

        $response->assertStatus( 200 );

        $entry = $this->findEntry( $response->json( 'data' ) ?? [], $product->id );

        $this->assertIsArray( $entry, 'The product was not part of its own search result.' );

        foreach ( [ 'units_prices', 'remaining_stock', 'barcodes' ] as $column ) {
            $this->assertArrayHasKey( $column, $entry, sprintf( 'The "%s" cell is missing from the row.', $column ) );
            $this->assertIsString( $entry[ $column ], sprintf( 'The "%s" cell has to be a string to render.', $column ) );
        }

        $unitQuantities = ProductUnitQuantity::withProduct( $product->id )->with( 'unit' )->get();

        foreach ( $unitQuantities as $unitQuantity ) {
            if ( $unitQuantity->unit !== null ) {
                $this->assertStringContainsString(
                    e( $unitQuantity->unit->name ),
                    $entry[ 'units_prices' ],
                    'A unit the product sells in is not named in the units cell.'
                );

                $this->assertStringContainsString(
                    e( $unitQuantity->unit->name ),
                    $entry[ 'remaining_stock' ],
                    'The remaining stock cell does not say which unit the figure is in.'
                );
            }

            $this->assertStringContainsString(
                e( ns()->currency->define( $unitQuantity->sale_price )->format() ),
                $entry[ 'units_prices' ],
                'A sale price the till would charge is missing from the units cell.'
            );

            if ( ! empty( $unitQuantity->barcode ) ) {
                $this->assertStringContainsString(
                    e( $unitQuantity->barcode ),
                    $entry[ 'barcodes' ],
                    'A barcode the product can be scanned with is missing from the barcode cell.'
                );
            }
        }
    }

    /**
     * A product with no unit row must still render, rather than emitting an empty cell
     * that reads as a blank line.
     */
    public function test_a_product_without_unit_rows_shows_a_placeholder(): void
    {
        $this->attemptAuthenticate();

        $product = Product::whereDoesntHave( 'unit_quantities' )->first();

        if ( ! $product instanceof Product ) {
            $product = Product::first();

            $this->assertInstanceOf( Product::class, $product, 'The test database holds no product.' );

            ProductUnitQuantity::withProduct( $product->id )->delete();
        }

        $response = $this->json( 'GET', 'api/crud/ns.products?per_page=200&search=' . urlencode( $product->name ) );

        $response->assertStatus( 200 );

        $entry = $this->findEntry( $response->json( 'data' ) ?? [], $product->id );

        $this->assertIsArray( $entry, 'The product was not part of its own search result.' );

        foreach ( [ 'units_prices', 'remaining_stock', 'barcodes' ] as $column ) {
            $this->assertSame( __( 'N/A' ), $entry[ $column ] );
        }
    }

    /**
     * The author join used to be an inner join, so a product whose author_id no longer
     * matched a row in the users table disappeared from the list. On the live database
     * that hid 1,776 of 1,785 products.
     */
    public function test_a_product_whose_author_is_gone_is_still_listed(): void
    {
        $this->attemptAuthenticate();

        $product = Product::first();

        $this->assertInstanceOf( Product::class, $product, 'The test database holds no product.' );

        $orphanId = (int) User::max( 'id' ) + 1000;

        DB::table( 'nexopos_products' )->where( 'id', $product->id )->update( [ 'author_id' => $orphanId ] );

        $expectedTotal = DB::table( 'nexopos_products' )->count();

        $response = $this->json( 'GET', 'api/crud/ns.products?per_page=1' );

        $response->assertStatus( 200 );

        $this->assertSame(
            $expectedTotal,
            (int) $response->json( 'total' ),
            'The list dropped products instead of showing every row the table holds.'
        );

        $response = $this->json( 'GET', 'api/crud/ns.products?per_page=200&search=' . urlencode( $product->name ) );

        $response->assertStatus( 200 );

        $entry = $this->findEntry( $response->json( 'data' ) ?? [], $product->id );

        $this->assertIsArray( $entry, 'A product with a missing author was left out of the list.' );
        $this->assertSame( __( 'N/A' ), $entry[ 'user_username' ] );
    }

    /**
     * A carton of twenty four with forty eight pieces on hand: the till sells two
     * cartons, so the list has to say two.
     *
     * The carton's own quantity column holds zero by design — the count lives on the base
     * unit and the carton is a price label — so reading it straight reported "0 หีบ" for
     * something plainly in stock.
     */
    public function test_a_label_unit_reports_what_the_base_count_allows(): void
    {
        $this->attemptAuthenticate();

        [ $product, $base, $carton ] = $this->makeCartonAndPieceProduct();

        $response = $this->json( 'GET', 'api/crud/ns.products?per_page=200&search=' . urlencode( $product->name ) );

        $response->assertStatus( 200 );

        $entry = $this->findEntry( $response->json( 'data' ) ?? [], $product->id );

        $this->assertIsArray( $entry, 'The product was not part of its own search result.' );

        $stock = strip_tags( str_replace( '</div>', ' ', $entry[ 'remaining_stock' ] ) );

        $this->assertMatchesRegularExpression(
            '/\b48\s*' . preg_quote( $base->name, '/' ) . '/u',
            $stock,
            sprintf( 'The base count is not reported as it stands. Cell read: %s', $stock )
        );

        $this->assertMatchesRegularExpression(
            '/=\s*2\s*' . preg_quote( $carton->name, '/' ) . '/u',
            $stock,
            sprintf( 'Forty eight pieces should read as two cartons of twenty four. Cell read: %s', $stock )
        );

        /**
         * The ratio belongs next to the price so the two can be checked against each
         * other.
         */
        $this->assertStringContainsString( '24', $entry[ 'units_prices' ] );
    }

    /**
     * The opposite arrangement: an ordinary NexoPOS product holds real stock on every
     * unit, and each figure has to be reported as it stands rather than converted.
     */
    public function test_an_ordinary_product_reports_each_unit_as_it_stands(): void
    {
        $this->attemptAuthenticate();

        [ $product, $base, $carton ] = $this->makeCartonAndPieceProduct( stockOnBaseUnit: false );

        $response = $this->json( 'GET', 'api/crud/ns.products?per_page=200&search=' . urlencode( $product->name ) );

        $response->assertStatus( 200 );

        $entry = $this->findEntry( $response->json( 'data' ) ?? [], $product->id );

        $this->assertIsArray( $entry, 'The product was not part of its own search result.' );

        $stock = strip_tags( str_replace( '</div>', ' ', $entry[ 'remaining_stock' ] ) );

        $this->assertMatchesRegularExpression( '/\b48\s*' . preg_quote( $base->name, '/' ) . '/u', $stock );
        $this->assertMatchesRegularExpression( '/\b12\s*' . preg_quote( $carton->name, '/' ) . '/u', $stock );
        $this->assertStringNotContainsString( '=', $stock, 'Nothing was converted, so no line should be marked as an equivalent.' );
    }

    /**
     * A piece priced at twenty and a carton of twenty four priced at four hundred, which
     * is the shape the shop's own catalogue uses.
     *
     * @return array{0: Product, 1: Unit, 2: Unit}
     */
    private function makeCartonAndPieceProduct( bool $stockOnBaseUnit = true ): array
    {
        $group = new UnitGroup;
        $group->name = 'crud-columns-' . uniqid();
        $group->author_id = 1;
        $group->save();

        $base = new Unit;
        $base->name = 'ชิ้นทดสอบ';
        $base->identifier = 'crud-piece-' . uniqid();
        $base->group_id = $group->id;
        $base->value = 1;
        $base->base_unit = true;
        $base->author_id = 1;
        $base->save();

        $carton = new Unit;
        $carton->name = 'หีบทดสอบ';
        $carton->identifier = 'crud-carton-' . uniqid();
        $carton->group_id = $group->id;
        $carton->value = 24;
        $carton->base_unit = false;
        $carton->author_id = 1;
        $carton->save();

        $product = new Product;
        $product->stock_on_base_unit = $stockOnBaseUnit;
        $product->name = 'สินค้าทดสอบคอลัมน์ ' . uniqid();
        $product->barcode = 'C' . uniqid();
        $product->sku = $product->barcode;
        $product->barcode_type = 'code128';
        $product->unit_group = $group->id;
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

        $this->makeUnitQuantity( $product->id, $base->id, quantity: 48, price: 20 );

        /**
         * With the opt in, the carton is a label and its own figure stays at zero so that
         * nothing can be sold from it directly.
         */
        $this->makeUnitQuantity( $product->id, $carton->id, quantity: $stockOnBaseUnit ? 0 : 12, price: 400 );

        return [ $product, $base, $carton ];
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

    /**
     * The three cells are assembled in PHP, so there is no column to order by. Asking
     * for one has to be refused rather than reaching the database and failing there.
     */
    public function test_sorting_by_an_assembled_column_is_refused(): void
    {
        $this->attemptAuthenticate();

        foreach ( [ 'units_prices', 'remaining_stock', 'barcodes' ] as $column ) {
            $response = $this->json( 'GET', 'api/crud/ns.products?active=' . $column . '&direction=asc' );

            $response->assertStatus( 403 );
            $response->assertJson( [ 'status' => 'error' ] );
        }
    }

    /**
     * Ordering by a real column still works, so the guard above did not turn sorting off
     * across the board.
     */
    public function test_sorting_by_a_real_column_still_works(): void
    {
        $this->attemptAuthenticate();

        $response = $this->json( 'GET', 'api/crud/ns.products?active=name&direction=asc&per_page=5' );

        $response->assertStatus( 200 );

        $names = collect( $response->json( 'data' ) ?? [] )->pluck( 'name' )->all();

        $sorted = $names;
        sort( $sorted, SORT_NATURAL | SORT_FLAG_CASE );

        $this->assertSame( $sorted, $names, 'The list came back out of order.' );
    }

    /**
     * The cells read the unit rows per product, so this keeps the cost from growing into
     * a query per unit on top of that.
     */
    public function test_building_the_cells_does_not_run_a_query_per_unit(): void
    {
        $this->attemptAuthenticate();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->json( 'GET', 'api/crud/ns.products?per_page=20' );

        $queries = count( DB::getQueryLog() );

        DB::disableQueryLog();

        $response->assertStatus( 200 );

        $returned = count( $response->json( 'data' ) ?? [] );

        $this->assertLessThan(
            2 * $returned + 25,
            $queries,
            sprintf( 'The list ran %d queries for %d products, which suggests a N+1.', $queries, $returned )
        );
    }
}
