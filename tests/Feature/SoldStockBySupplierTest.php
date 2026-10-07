<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Procurement;
use App\Models\ProcurementProduct;
use App\Models\Provider;
use App\Services\OrdersService;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Which supplier a sale came from, answered from the purchase it was taken from.
 *
 * The shop chose this route over tagging each product with a supplier, and it is the stricter of the
 * two: the chain is order line -> purchase batch -> purchase -> supplier, and every link is a record of
 * something that happened rather than a guess. A product bought from three suppliers over a year
 * reports against whichever one the goods actually sold came from.
 *
 * It depends on the till writing the batch down, and it never did. `procurement_product_id` has been on
 * the order products table all along and nothing wrote to it, so every sold line on the shop's database
 * carries null — 58 out of 58. ProductsController resolves the batch when a barcode identifies one and
 * its own comment says it does so to "track how products are sold"; the value was then dropped in
 * OrdersService and never reached the row. That is fixed, which is what makes this report possible at
 * all, and it is why lines recorded before the fix report as unattributed rather than being guessed at.
 */
class SoldStockBySupplierTest extends TestCase
{
    use WithAuthentication;

    private function paidLine(): ?OrderProduct
    {
        return OrderProduct::whereHas(
            'order',
            fn ( $query ) => $query->where( 'payment_status', Order::PAYMENT_PAID )
        )->orderBy( 'id', 'desc' )->first();
    }

    public function test_the_till_records_which_purchase_a_sale_was_taken_from(): void
    {
        $source = (string) file_get_contents( base_path( 'app/Services/OrdersService.php' ) );

        $this->assertStringContainsString(
            '$orderProduct->procurement_product_id',
            $source,
            'Sales no longer record the purchase batch they came from, so no supplier can be named for'
                . ' any of them and this whole report goes back to being empty.'
        );
    }

    public function test_a_sold_line_reports_the_supplier_of_the_purchase_behind_it(): void
    {
        $this->attemptAuthenticate();

        $line = $this->paidLine();

        if ( ! $line instanceof OrderProduct ) {
            $this->markTestSkipped( 'No paid sale to report on.' );
        }

        $provider = Provider::first();

        if ( ! $provider instanceof Provider ) {
            $this->markTestSkipped( 'No supplier on file.' );
        }

        /**
         * Assigned rather than mass created, because Procurement guards its columns.
         */
        $procurement = new Procurement;
        $procurement->name = 'ทดสอบรายงานตามซัพพลายเออร์ ' . random_int( 100000, 999999 );
        $procurement->provider_id = $provider->id;
        $procurement->author_id = auth()->id() ?? 1;
        $procurement->delivery_status = Procurement::STOCKED;
        $procurement->payment_status = Procurement::PAYMENT_PAID;
        $procurement->save();

        $batch = new ProcurementProduct;
        $batch->name = $line->name;
        $batch->procurement_id = $procurement->id;
        $batch->product_id = $line->product_id;
        $batch->unit_id = $line->unit_id;
        $batch->quantity = 1;
        $batch->tax_type = 'inclusive';
        $batch->purchase_price = 1;
        $batch->total_purchase_price = 1;
        $batch->author_id = $procurement->author_id;
        $batch->save();

        $originalBatch = $line->procurement_product_id;

        $line->procurement_product_id = $batch->id;
        $line->save();

        try {
            $rows = app( OrdersService::class )->getSoldStock(
                $line->created_at->copy()->startOfDay()->toDateTimeString(),
                $line->created_at->copy()->endOfDay()->toDateTimeString(),
                [],
                []
            );

            $attributed = $rows->firstWhere( 'provider_id', $provider->id );

            $this->assertNotNull(
                $attributed,
                'A sale taken from a purchase batch did not report the supplier on that purchase.'
            );

            /**
             * Narrowed to that supplier, the line is still there.
             */
            $filtered = app( OrdersService::class )->getSoldStock(
                $line->created_at->copy()->startOfDay()->toDateTimeString(),
                $line->created_at->copy()->endOfDay()->toDateTimeString(),
                [],
                [],
                [ $provider->id ]
            );

            $this->assertGreaterThan(
                0,
                $filtered->count(),
                'Filtering by the supplier the sale came from returned nothing.'
            );

            foreach ( $filtered as $row ) {
                $this->assertSame(
                    (int) $provider->id,
                    (int) $row->provider_id,
                    'Filtering by one supplier returned a row belonging to another.'
                );
            }
        } finally {
            $line->procurement_product_id = $originalBatch;
            $line->save();
            $batch->delete();
            $procurement->delete();
        }
    }

    /**
     * Lines sold from pooled stock have no batch, so no supplier can be named for them. They must stay
     * visible and reachable, or the supplier views stop adding up to the unfiltered report.
     */
    public function test_sales_with_no_purchase_behind_them_are_reported_as_unattributed(): void
    {
        $this->attemptAuthenticate();

        $line = $this->paidLine();

        if ( ! $line instanceof OrderProduct ) {
            $this->markTestSkipped( 'No paid sale to report on.' );
        }

        $start = $line->created_at->copy()->startOfDay()->toDateTimeString();
        $end = $line->created_at->copy()->endOfDay()->toDateTimeString();

        $everything = app( OrdersService::class )->getSoldStock( $start, $end, [], [] );

        $withoutASupplier = $everything->whereNull( 'provider_id' );

        if ( $withoutASupplier->isEmpty() ) {
            $this->markTestSkipped( 'Every sale in range is attributed, so there is nothing to check here.' );
        }

        $unattributed = app( OrdersService::class )->getSoldStock( $start, $end, [], [], [ 'unattributed' ] );

        $this->assertSame(
            $withoutASupplier->count(),
            $unattributed->count(),
            'Asking for the unattributed sales did not return the sales that have no supplier.'
        );

        foreach ( $unattributed as $row ) {
            $this->assertNull( $row->provider_id );
        }
    }

    /**
     * The endpoint the screen calls has to carry a name for the column, including for the rows that
     * have no supplier — a blank cell there reads as a missing name rather than as "none recorded".
     */
    public function test_the_endpoint_names_the_supplier_on_every_row(): void
    {
        $this->attemptAuthenticate();

        $line = $this->paidLine();

        if ( ! $line instanceof OrderProduct ) {
            $this->markTestSkipped( 'No paid sale to report on.' );
        }

        $payload = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/reports/sold-stock-report', [
                'startDate' => $line->created_at->copy()->startOfDay()->toDateTimeString(),
                'endDate' => $line->created_at->copy()->endOfDay()->toDateTimeString(),
                'categories' => [],
                'units' => [],
                'providers' => [],
            ] )->json();

        $this->assertNotEmpty( $payload );

        foreach ( $payload as $row ) {
            $this->assertArrayHasKey( 'provider_name', $row );
            $this->assertArrayHasKey( 'provider_id', $row );
            $this->assertNotSame( '', trim( (string) $row[ 'provider_name' ] ), 'A row has an empty supplier cell.' );
        }
    }

    /**
     * The screen has to send the filter, or the button is decoration.
     */
    public function test_the_screen_sends_the_supplier_filter_and_shows_the_column(): void
    {
        $source = (string) file_get_contents(
            base_path( 'resources/ts/pages/dashboard/reports/ns-sold-stock-report.vue' )
        );

        $this->assertStringContainsString( 'selectProviders', $source, 'No way to choose a supplier.' );
        $this->assertStringContainsString( 'providers: this.providerField.value', $source, 'The filter is never sent.' );
        $this->assertStringContainsString( 'product.provider_name', $source, 'The supplier is never displayed.' );
        $this->assertStringContainsString( '/api/providers', $source, 'The picker has nothing to list.' );
    }
}
