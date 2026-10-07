<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Procurement;
use App\Models\ProcurementProduct;
use App\Models\Product;
use App\Models\Provider;
use App\Services\OrdersService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Two suppliers can be known about one sold line, and the report has to keep them apart.
 *
 * The purchase batch says who a delivery was actually bought from. That is evidence — goods arrived, an
 * invoice was settled, a provider was named on the document. The product's own supplier says where the
 * shop normally buys that item. That is a habit, and a habit is not proof of anything about a particular
 * sale.
 *
 * Both are useful and the shop needs both, because until the till started recording batches every
 * historical sale was unattributable and the accounts had no supplier against any of it. But a document
 * used to close the books must not present the second as though it were the first. So the report prefers
 * the batch, falls back to the product, and states on every row which of the two it used. An auditor
 * asking "where did this figure come from" gets a different answer for each, and the row says which
 * answer applies before they have to ask.
 *
 * The filter obeys the same order of preference. If it did not, a line bought from one supplier and
 * normally bought from another would show up under both, and the supplier views would add up to more
 * than the report they are drawn from.
 */
class SoldStockSupplierSourceTest extends TestCase
{
    use WithAuthentication;

    /**
     * The most recent paid line whose product still exists.
     *
     * Taking simply the most recent one coupled these tests to whatever ran before them: an earlier test
     * that sells a temporary product and then removes it left the newest paid line pointing at nothing, and
     * all four assertions below excused themselves with "the sold product no longer exists" — reporting
     * green while checking nothing. What these tests need is a line they can still resolve a product for,
     * so that is what they ask for.
     */
    private function paidLine(): ?OrderProduct
    {
        return OrderProduct::whereHas(
            'order',
            fn ( $query ) => $query->where( 'payment_status', Order::PAYMENT_PAID )
        )
            ->whereHas( 'product' )
            ->orderBy( 'id', 'desc' )
            ->first();
    }

    /**
     * Suppliers are created here rather than taken from whatever the seeders left behind.
     *
     * Reading them off the database made the two tests that matter most skip themselves, because the
     * test install carries fewer than two suppliers — so the assertions that justify preferring the
     * purchase batch never ran at all. A test that quietly excuses itself is worse than no test: it
     * reports green while proving nothing.
     */
    private function supplier( string $label ): Provider
    {
        $provider = new Provider;
        $provider->first_name = $label . ' ' . random_int( 100000, 999999 );
        $provider->author_id = auth()->id() ?? 1;
        $provider->save();

        return $provider;
    }

    /**
     * @return array{0: Procurement, 1: ProcurementProduct}
     */
    private function batchFrom( Provider $provider, OrderProduct $line ): array
    {
        $procurement = new Procurement;
        $procurement->name = 'ทดสอบที่มาของซัพพลายเออร์ ' . random_int( 100000, 999999 );
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

        return [ $procurement, $batch ];
    }

    public function test_the_products_table_can_hold_a_default_supplier(): void
    {
        $this->assertTrue(
            Schema::hasColumn( 'nexopos_products', 'provider_id' ),
            'Products cannot record a supplier, so sales with no purchase batch can never be attributed'
                . ' and the report stays empty for the whole existing catalogue.'
        );
    }

    /**
     * The batch wins. It has to: it is the only one of the two that describes this sale.
     */
    public function test_the_purchase_batch_outranks_the_products_own_supplier(): void
    {
        $this->attemptAuthenticate();

        $line = $this->paidLine();

        if ( ! $line instanceof OrderProduct ) {
            $this->markTestSkipped( 'No paid sale to report on.' );
        }

        $product = Product::find( $line->product_id );

        if ( ! $product instanceof Product ) {
            $this->markTestSkipped( 'The sold product no longer exists.' );
        }

        $bought = $this->supplier( 'ผู้ขายจริง' );
        $usual = $this->supplier( 'ผู้ขายประจำ' );

        [ $procurement, $batch ] = $this->batchFrom( $bought, $line );

        $originalBatch = $line->procurement_product_id;
        $originalProvider = $product->provider_id;

        $line->procurement_product_id = $batch->id;
        $line->save();

        $product->provider_id = $usual->id;
        $product->save();

        try {
            $row = $this->rowFor(
                $line,
                fn ( $rows ) => $rows->firstWhere( 'provider_id', $bought->id )
            );

            $this->assertSame(
                (int) $bought->id,
                (int) $row->provider_id,
                'The report named the usual supplier instead of the one the goods were actually bought from.'
            );

            $this->assertSame(
                (int) $usual->id,
                (int) $row->catalogue_provider_id,
                'The product default was not carried alongside, so nothing can label the row.'
            );

            $payload = collect( $this->endpointFor( $line ) )->firstWhere( 'provider_id', $bought->id );

            $this->assertNotNull( $payload, 'The endpoint dropped the row the batch supplier belongs to.' );

            $this->assertSame(
                'procurement',
                $payload[ 'provider_source' ],
                'A row backed by a purchase document does not say so, so nobody can tell it apart from a'
                    . ' guess made from the product default.'
            );

            $this->assertSame( __( 'จากใบสั่งซื้อ' ), $payload[ 'provider_source_label' ] );
        } finally {
            $line->procurement_product_id = $originalBatch;
            $line->save();
            $product->provider_id = $originalProvider;
            $product->save();
            $batch->delete();
            $procurement->delete();
            $bought->delete();
            $usual->delete();
        }
    }

    /**
     * No batch, so the product's supplier is all there is. It gets used, and it gets labelled.
     */
    public function test_a_sale_with_no_batch_falls_back_to_the_products_supplier_and_says_where_it_came_from(): void
    {
        $this->attemptAuthenticate();

        $line = $this->paidLine();

        if ( ! $line instanceof OrderProduct ) {
            $this->markTestSkipped( 'No paid sale to report on.' );
        }

        $product = Product::find( $line->product_id );

        if ( ! $product instanceof Product ) {
            $this->markTestSkipped( 'The sold product no longer exists.' );
        }

        $supplier = $this->supplier( 'ผู้ขายประจำ' );

        $originalBatch = $line->procurement_product_id;
        $originalProvider = $product->provider_id;

        $line->procurement_product_id = null;
        $line->save();

        $product->provider_id = $supplier->id;
        $product->save();

        try {
            $payload = $this->endpointFor( $line );

            $named = collect( $payload )->firstWhere( 'provider_id', $supplier->id );

            $this->assertNotNull(
                $named,
                'A sale with no purchase batch did not fall back to the supplier recorded on the product.'
            );

            $this->assertSame(
                'catalogue',
                $named[ 'provider_source' ],
                'The row was attributed from the product default but does not say so, which lets a habit'
                    . ' be read as a purchase record.'
            );

            $this->assertSame( __( 'จากทะเบียนสินค้า' ), $named[ 'provider_source_label' ] );
        } finally {
            $line->procurement_product_id = $originalBatch;
            $line->save();
            $product->provider_id = $originalProvider;
            $product->save();
            $supplier->delete();
        }
    }

    /**
     * Neither known. Nothing is invented, and the row stays on the report.
     */
    public function test_a_sale_with_neither_supplier_is_reported_as_unattributed(): void
    {
        $this->attemptAuthenticate();

        $line = $this->paidLine();

        if ( ! $line instanceof OrderProduct ) {
            $this->markTestSkipped( 'No paid sale to report on.' );
        }

        $product = Product::find( $line->product_id );

        if ( ! $product instanceof Product ) {
            $this->markTestSkipped( 'The sold product no longer exists.' );
        }

        $originalBatch = $line->procurement_product_id;
        $originalProvider = $product->provider_id;

        $line->procurement_product_id = null;
        $line->save();

        $product->provider_id = null;
        $product->save();

        try {
            $payload = $this->endpointFor( $line );

            $unattributed = collect( $payload )->where( 'provider_source', 'none' );

            $this->assertGreaterThan(
                0,
                $unattributed->count(),
                'A sale with no batch and no product supplier vanished instead of being reported as'
                    . ' unattributed.'
            );

            foreach ( $unattributed as $row ) {
                $this->assertNull( $row[ 'provider_id' ] );
                $this->assertSame( __( 'ไม่ระบุซัพพลายเออร์' ), $row[ 'provider_name' ] );
            }
        } finally {
            $line->procurement_product_id = $originalBatch;
            $line->save();
            $product->provider_id = $originalProvider;
            $product->save();
        }
    }

    /**
     * The filter has to answer the same question the row answers.
     *
     * A line bought from one supplier, and normally bought from another, belongs to the first and only
     * the first. If the second's filter also returned it, the same money would appear under two
     * suppliers and the totals would stop reconciling.
     */
    public function test_filtering_follows_the_same_order_of_preference_as_the_report(): void
    {
        $this->attemptAuthenticate();

        $line = $this->paidLine();

        if ( ! $line instanceof OrderProduct ) {
            $this->markTestSkipped( 'No paid sale to report on.' );
        }

        $product = Product::find( $line->product_id );

        if ( ! $product instanceof Product ) {
            $this->markTestSkipped( 'The sold product no longer exists.' );
        }

        $bought = $this->supplier( 'ผู้ขายจริง' );
        $usual = $this->supplier( 'ผู้ขายประจำ' );

        [ $procurement, $batch ] = $this->batchFrom( $bought, $line );

        $originalBatch = $line->procurement_product_id;
        $originalProvider = $product->provider_id;

        $line->procurement_product_id = $batch->id;
        $line->save();

        $product->provider_id = $usual->id;
        $product->save();

        try {
            $start = $line->created_at->copy()->startOfDay()->toDateTimeString();
            $end = $line->created_at->copy()->endOfDay()->toDateTimeString();
            $service = app( OrdersService::class );

            $byBuyer = $service->getSoldStock( $start, $end, [], [], [ $bought->id ] );

            $this->assertGreaterThan(
                0,
                $byBuyer->where( 'product_id', $line->product_id )->count(),
                'Filtering by the supplier the goods were bought from lost the sale.'
            );

            $byUsual = $service->getSoldStock( $start, $end, [], [], [ $usual->id ] );

            $this->assertSame(
                0,
                $byUsual->where( 'product_id', $line->product_id )
                    ->where( 'provider_id', $bought->id )
                    ->count(),
                'A sale with a purchase batch was also returned under the product default supplier, so'
                    . ' the same money is counted under two suppliers.'
            );
        } finally {
            $line->procurement_product_id = $originalBatch;
            $line->save();
            $product->provider_id = $originalProvider;
            $product->save();
            $batch->delete();
            $procurement->delete();
            $bought->delete();
            $usual->delete();
        }
    }

    /**
     * Every supplier view added together has to equal the report with no filter at all. If it does not,
     * somebody closing the books is reconciling against a number that does not exist.
     */
    public function test_the_supplier_views_add_up_to_the_unfiltered_report(): void
    {
        $this->attemptAuthenticate();

        $line = $this->paidLine();

        if ( ! $line instanceof OrderProduct ) {
            $this->markTestSkipped( 'No paid sale to report on.' );
        }

        $start = $line->created_at->copy()->startOfDay()->toDateTimeString();
        $end = $line->created_at->copy()->endOfDay()->toDateTimeString();
        $service = app( OrdersService::class );

        $everything = $service->getSoldStock( $start, $end, [], [] );

        $resolved = $everything->map(
            fn ( $row ) => $row->provider_id ?? $row->catalogue_provider_id
        );

        $filters = $resolved->filter()->unique()->values()->all();

        if ( $resolved->contains( null ) ) {
            $filters[] = 'unattributed';
        }

        if ( empty( $filters ) ) {
            $this->markTestSkipped( 'Nothing sold in range.' );
        }

        $subsetTotal = 0.0;

        foreach ( $filters as $filter ) {
            $subsetTotal += (float) $service->getSoldStock( $start, $end, [], [], [ $filter ] )
                ->sum( 'total_price' );
        }

        $this->assertEquals(
            round( (float) $everything->sum( 'total_price' ), 2 ),
            round( $subsetTotal, 2 ),
            'The supplier views do not add up to the unfiltered report, so at least one sale is either'
                . ' missing from every view or counted in two of them.'
        );
    }

    /**
     * The screen has to show the source, not just the name. Two rows can carry the same supplier name
     * for entirely different reasons and the name alone cannot tell them apart.
     */
    public function test_the_screen_shows_where_the_supplier_came_from(): void
    {
        $source = (string) file_get_contents(
            base_path( 'resources/ts/pages/dashboard/reports/ns-sold-stock-report.vue' )
        );

        $this->assertStringContainsString(
            'product.provider_source_label',
            $source,
            'The report shows a supplier without saying whether it came from a purchase document or from'
                . ' the product default.'
        );
    }

    /**
     * Without this the column exists and stays empty forever: nothing in the shop's workflow would ever
     * put a supplier on a product.
     */
    public function test_a_supplier_can_be_chosen_on_the_product_form(): void
    {
        $source = (string) file_get_contents( base_path( 'app/Crud/ProductCrud.php' ) );

        $this->assertStringContainsString(
            "'name' => 'provider_id'",
            $source,
            'There is no way to set a product supplier by hand, so anything the import misses can never'
                . ' be corrected.'
        );
    }

    /**
     * The import already creates every supplier in the shop's file. It has to attach them too, or the
     * 2,645 products that already exist stay unattributed and the whole point is lost.
     */
    public function test_the_catalogue_import_attaches_the_supplier_to_the_product(): void
    {
        $source = (string) file_get_contents(
            base_path( 'app/Console/Commands/ImportThaiProductCatalogCommand.php' )
        );

        $this->assertStringContainsString(
            '$product->provider_id',
            $source,
            'The import reads the supplier column and creates the suppliers, then throws the link away —'
                . ' which is exactly why no sale could name a supplier before this.'
        );
    }

    /**
     * @param callable(Collection): mixed $pick
     */
    private function rowFor( OrderProduct $line, callable $pick ): object
    {
        $rows = app( OrdersService::class )->getSoldStock(
            $line->created_at->copy()->startOfDay()->toDateTimeString(),
            $line->created_at->copy()->endOfDay()->toDateTimeString(),
            [],
            []
        );

        $row = $pick( $rows );

        $this->assertNotNull( $row, 'The sold line did not appear on the report at all.' );

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function endpointFor( OrderProduct $line ): array
    {
        return $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/reports/sold-stock-report', [
                'startDate' => $line->created_at->copy()->startOfDay()->toDateTimeString(),
                'endDate' => $line->created_at->copy()->endOfDay()->toDateTimeString(),
                'categories' => [],
                'units' => [],
                'providers' => [],
            ] )->json();
    }
}
