<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Services\OrdersService;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * A report that opens empty is a report that does not work.
 *
 * The shop reported that the reports "do not work at all". They were right about the symptom and the
 * cause was not in the figures: called directly, every report service on the live database returns
 * rows. Every report page is a blade shell that mounts a Vue component and renders nothing itself, and
 * nine of the fifteen components never called their loader when they opened. The page arrived with its
 * title, its filter buttons, an empty table and a zero total, and nothing anywhere said that a button
 * had to be pressed first.
 *
 * The existing coverage could not catch it. WithReportTest::attemptSeeReports() asserts each page
 * answers 200, which it always did — the emptiness is on the other side of the mount.
 *
 * So this reads the components. It is a source check rather than a browser one, which is the honest
 * limit of what can be asserted without driving a real browser, but it holds the one property that was
 * broken: opening the screen asks the server for figures.
 */
class ReportsLoadTheirFiguresTest extends TestCase
{
    use WithAuthentication;

    /**
     * Component => the loader it must call when it opens.
     *
     * @var array<string, string>
     */
    private const MUST_LOAD_ON_OPEN = [
        'ns-best-products-report' => 'loadReport',
        'ns-cash-flow-report' => 'loadReport',
        'ns-delivery-notes-report' => 'loadReport',
        'ns-low-stock-report' => 'loadRelevantReport',
        'ns-payment-types-report' => 'loadReport',
        'ns-profit-report' => 'loadReport',
        'ns-receivables-report' => 'loadReport',
        'ns-sale-report' => 'loadReport',
        'ns-sold-stock-report' => 'loadReport',
        'ns-stock-combined-report' => 'loadReport',
        'ns-stock-report' => 'loadReport',
        'ns-vat-return-report' => 'loadReport',
        'ns-withholding-tax-report' => 'loadReport',
        'ns-yearly-report' => 'loadReport',
    ];

    private function componentSource( string $component ): string
    {
        $path = base_path( 'resources/ts/pages/dashboard/reports/' . $component . '.vue' );

        $this->assertFileExists( $path, sprintf( 'The %s report component is gone.', $component ) );

        return (string) file_get_contents( $path );
    }

    /**
     * @return string the body of mounted(), or null when there is none
     */
    private function mountedBody( string $source ): ?string
    {
        if ( preg_match( '/\bmounted\s*\(\s*\)\s*\{(.*?)\n(\s*)\},/s', $source, $matches ) !== 1 ) {
            return null;
        }

        return trim( $matches[ 1 ] );
    }

    public function test_every_report_screen_asks_for_its_figures_when_it_opens(): void
    {
        $silent = [];

        foreach ( self::MUST_LOAD_ON_OPEN as $component => $loader ) {
            $body = $this->mountedBody( $this->componentSource( $component ) );

            if ( $body === null ) {
                $silent[] = $component . ' has no mounted() at all';

                continue;
            }

            if ( ! str_contains( $body, 'this.' . $loader . '(' ) ) {
                $silent[] = sprintf( '%s opens without calling %s()', $component, $loader );
            }
        }

        $this->assertSame(
            [],
            $silent,
            "These report screens open with an empty table and never ask the server for anything:\n"
                . implode( "\n", $silent )
        );
    }

    /**
     * The customer statement is the one screen that genuinely cannot load on open — it has nothing to
     * show until somebody picks a customer. Pinned so that it is a decision rather than an oversight,
     * and so nobody 'fixes' it by firing a request for a customer that has not been chosen.
     */
    public function test_the_customer_statement_waits_for_a_customer_to_be_chosen(): void
    {
        $source = $this->componentSource( 'ns-customers-statement-report' );

        /**
         * It fetches per customer, so there is nothing it could request before one is chosen.
         */
        $this->assertStringContainsString(
            'customers-statement/${customer.id}',
            $source,
            'The customer statement no longer loads against a chosen customer, so it could load on open'
                . ' like the rest and this exemption should go.'
        );
    }

    /**
     * The sold stock report, end to end through the endpoint the screen actually calls.
     *
     * Its figures were never the problem, so this is here to keep them that way — and to cover the
     * report that WithReportTest::attemptLoadReports() leaves out.
     */
    public function test_the_sold_stock_endpoint_returns_the_products_of_a_paid_sale(): void
    {
        $this->attemptAuthenticate();

        $line = OrderProduct::whereHas(
            'order',
            fn ( $query ) => $query->where( 'payment_status', Order::PAYMENT_PAID )
        )->orderBy( 'id', 'desc' )->first();

        if ( ! $line instanceof OrderProduct ) {
            $this->markTestSkipped( 'No paid sale in the database to report on.' );
        }

        $rows = app( OrdersService::class )->getSoldStock(
            $line->created_at->copy()->startOfDay()->toDateTimeString(),
            $line->created_at->copy()->endOfDay()->toDateTimeString(),
            [],
            []
        );

        $this->assertGreaterThan(
            0,
            $rows->count(),
            'The sold stock report found nothing on a day that has a paid sale on it.'
        );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/reports/sold-stock-report', [
                'startDate' => $line->created_at->copy()->startOfDay()->toDateTimeString(),
                'endDate' => $line->created_at->copy()->endOfDay()->toDateTimeString(),
                'categories' => [],
                'units' => [],
            ] );

        $response->assertOk();

        $payload = $response->json();

        $this->assertNotEmpty( $payload, 'The endpoint the screen calls returned nothing.' );

        $first = $payload[ 0 ];

        foreach ( [ 'name', 'unit_name', 'quantity', 'total_price', 'tax_value' ] as $key ) {
            $this->assertArrayHasKey( $key, $first, sprintf( 'The report row lost its %s.', $key ) );
        }
    }

    /**
     * Two keys used to be served on every row and both were always wrong: `mode` came back null and
     * `unit_price` came back 0.00, because getSoldStock() rebuilds its select list and drops them
     * before the query runs. Neither screen displays them, so the zeros were invisible.
     */
    public function test_the_report_does_not_serve_figures_it_never_selected(): void
    {
        $this->attemptAuthenticate();

        $line = OrderProduct::whereHas(
            'order',
            fn ( $query ) => $query->where( 'payment_status', Order::PAYMENT_PAID )
        )->orderBy( 'id', 'desc' )->first();

        if ( ! $line instanceof OrderProduct ) {
            $this->markTestSkipped( 'No paid sale in the database to report on.' );
        }

        $payload = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/reports/sold-stock-report', [
                'startDate' => $line->created_at->copy()->startOfDay()->toDateTimeString(),
                'endDate' => $line->created_at->copy()->endOfDay()->toDateTimeString(),
                'categories' => [],
                'units' => [],
            ] )->json();

        $this->assertNotEmpty( $payload );

        foreach ( [ 'mode', 'unit_price' ] as $key ) {
            $this->assertArrayNotHasKey(
                $key,
                $payload[ 0 ],
                sprintf(
                    'The report is serving "%s" again. getSoldStock() does not select it, so its value'
                        . ' cannot be anything but null or zero.',
                    $key
                )
            );
        }
    }
}
