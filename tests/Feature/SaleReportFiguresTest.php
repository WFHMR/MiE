<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Covers the figures on the sales report at dashboard/reports/sales.
 *
 * Two faults sat on this screen. The profit in the summary panel had the tax taken off twice, which
 * turned a profitable period into a loss. And the totals row of the categories table asked each
 * category for a key it does not carry, so the tax and profit totals printed "NaN" — the one place a
 * shop looks to check a day's takings showed no number at all.
 */
class SaleReportFiguresTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    /**
     * Figures chosen so every quantity in the assertions is checkable by hand.
     */
    private const TOTAL = 1000.00;

    private const TAX = 70.00;

    private const COST = 600.00;

    private const DISCOUNT = 50.00;

    /**
     * Builds one paid sale with known amounts, so the report is measured against arithmetic rather than
     * against whatever other tests happened to leave behind.
     *
     * The report only counts paid orders, so an unpaid one would be invisible and the test would pass
     * for the wrong reason.
     */
    private function givenPaidSale(): Order
    {
        $category = ProductCategory::first() ?? ProductCategory::factory()->create();

        $order = new Order;
        $order->code = 'TEST-' . uniqid();
        $order->type = 'takeaway';
        $order->payment_status = Order::PAYMENT_PAID;
        $order->customer_id = Customer::first()?->id ?? 0;
        $order->author_id = User::first()->id;
        $order->subtotal = self::TOTAL;
        $order->total = self::TOTAL;
        $order->tax_value = self::TAX;
        $order->discount = 0;
        $order->created_at = ns()->date->getNow()->toDateTimeString();
        $order->save();

        $product = new OrderProduct;
        $product->order_id = $order->id;
        $product->name = 'สินค้าทดสอบรายงาน';
        $product->product_id = 0;
        $product->unit_id = 0;
        $product->unit_quantity_id = 0;
        $product->product_category_id = $category->id;
        $product->quantity = 1;
        $product->unit_price = self::TOTAL;

        /**
         * total_price is stored already discounted, which is the whole reason subtracting the discount
         * again in the profit column was wrong. The discount is set to a non zero figure here so a
         * formula that subtracts it a second time cannot pass unnoticed.
         */
        $product->discount = self::DISCOUNT;
        $product->total_price = self::TOTAL;
        $product->total_purchase_price = self::COST;
        $product->tax_value = self::TAX;
        $product->save();

        return $order;
    }

    private function reportForAllOrders( string $type = 'categories_report' ): array
    {
        $first = Order::orderBy( 'created_at' )->value( 'created_at' );
        $last = Order::orderByDesc( 'created_at' )->value( 'created_at' );

        $this->assertNotNull( $first, 'No order exists on the test database, so the report has nothing to summarise.' );

        return app()->make( ReportService::class )->getSaleReport(
            substr( (string) $first, 0, 10 ) . ' 00:00:00',
            substr( (string) $last, 0, 10 ) . ' 23:59:59',
            $type
        );
    }

    public function test_the_profit_of_one_known_sale_has_the_tax_taken_off_once(): void
    {
        $this->attemptAuthenticate();

        Order::query()->delete();

        $this->givenPaidSale();

        $summary = $this->reportForAllOrders()[ 'summary' ];

        /**
         * 1000 taken, 600 of goods, 70 of tax. Nothing else is in the period.
         */
        $this->assertEquals( self::TOTAL, round( (float) $summary[ 'total' ], 2 ) );
        $this->assertEquals( self::COST, round( (float) $summary[ 'total_purchase_price' ], 2 ) );
        $this->assertEquals( self::TAX, round( (float) $summary[ 'sales_taxes' ], 2 ) );

        $this->assertEquals(
            330.00,
            round( (float) $summary[ 'profit' ], 2 ),
            'Profit is the takings less the cost of goods less the tax charged: 1000 - 600 - 70. Taking the tax off twice gives 260.'
        );
    }

    public function test_the_profit_is_not_reduced_by_the_line_taxes_a_second_time(): void
    {
        $this->attemptAuthenticate();

        Order::query()->delete();

        $this->givenPaidSale();

        $summary = $this->reportForAllOrders()[ 'summary' ];

        $this->assertGreaterThan(
            0,
            (float) $summary[ 'product_taxes' ],
            'The sale has to carry a line tax, or nothing could be counted twice and this proves nothing.'
        );

        $this->assertNotEquals(
            260.00,
            round( (float) $summary[ 'profit' ], 2 ),
            'The reported profit still matches the figure produced by subtracting the same tax twice.'
        );
    }

    public function test_sales_whose_category_was_deleted_still_appear_in_the_table(): void
    {
        $this->attemptAuthenticate();

        Order::query()->delete();

        $order = $this->givenPaidSale();

        /**
         * Removed at database level, which is the state left behind by a catalogue reorganisation: the
         * sold line still records the id it had at the time of sale.
         */
        $categoryId = (int) OrderProduct::where( 'order_id', $order->id )->value( 'product_category_id' );
        DB::table( 'nexopos_products_categories' )->where( 'id', $categoryId )->delete();

        $report = $this->reportForAllOrders();

        $tableTotal = collect( $report[ 'result' ] )
            ->map( fn( $row ) => (float) ( ( (array) $row )[ 'total_price' ] ?? 0 ) )
            ->sum();

        $this->assertEquals(
            self::TOTAL,
            round( $tableTotal, 2 ),
            'The rows of the table have to add up to what was taken. Sales of a deleted category used to vanish from the table while still counting in the summary above it.'
        );
    }

    public function test_the_table_reconciles_with_the_summary(): void
    {
        $this->attemptAuthenticate();

        Order::query()->delete();

        $this->givenPaidSale();

        $report = $this->reportForAllOrders();

        $tableTotal = collect( $report[ 'result' ] )
            ->map( fn( $row ) => (float) ( ( (array) $row )[ 'total_price' ] ?? 0 ) )
            ->sum();

        $this->assertEquals(
            round( (float) $report[ 'summary' ][ 'total' ], 2 ),
            round( $tableTotal, 2 ),
            'The detail and the total on the same screen must agree, or there is no way to tell which one to believe.'
        );
    }

    public function test_a_category_row_carries_a_tax_total(): void
    {
        $this->attemptAuthenticate();

        $this->givenPaidSale();

        $categories = $this->reportForAllOrders()[ 'result' ];

        $this->assertNotEmpty( $categories, 'No category came back, so the table on screen would be empty.' );

        foreach ( $categories as $category ) {
            $this->assertArrayHasKey(
                'total_tax_value',
                (array) $category,
                'The totals row of the table sums this key on every category.'
            );
        }
    }

    /**
     * The guard that would have caught the fault in the first place.
     *
     * Reads every key the screen sums out of the component itself and checks the service actually
     * supplies it. A misspelling here does not fail loudly — it silently produces NaN — so nothing else
     * in the suite would notice.
     */
    public function test_the_screen_only_sums_keys_the_report_supplies(): void
    {
        $this->attemptAuthenticate();

        $this->givenPaidSale();

        /**
         * "result" holds a different shape per report type — categories for the two category views,
         * products for the product view — so each block is checked against the rows it will actually be
         * given.
         */
        $expectations = [
            'categories_report' => $this->firstRowOf( 'categories_report' ),
            'categories_summary' => $this->firstRowOf( 'categories_summary' ),
            'products_report' => $this->firstRowOf( 'products_report' ),
        ];

        $problems = [];

        foreach ( $expectations as $type => $row ) {
            $this->assertNotEmpty( $row, sprintf( 'The %s report returned no rows to check against.', $type ) );

            $summedKeys = $this->keysSummedInBlock( $type );

            $this->assertNotEmpty( $summedKeys, sprintf( 'No summed keys found for %s, so this guard checks nothing.', $type ) );

            foreach ( $summedKeys as $key ) {
                if ( ! array_key_exists( $key, $row ) ) {
                    $problems[] = sprintf( '%s sums "%s", which its rows do not carry', $type, $key );
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            "The report screen sums keys its rows do not carry, which renders as NaN:\n" . implode( "\n", $problems )
        );
    }

    /**
     * The first row of a report as the browser would receive it.
     *
     * The category reports hand back plain arrays while the product report hands back models, and
     * casting a model to an array yields mangled internal keys rather than its attributes — so each is
     * converted the way the JSON response would convert it.
     *
     * @return array<string, mixed>
     */
    private function firstRowOf( string $type ): array
    {
        $row = collect( $this->reportForAllOrders( $type )[ 'result' ] )->first();

        if ( $row instanceof Arrayable ) {
            return $row->toArray();
        }

        if ( is_object( $row ) ) {
            return json_decode( json_encode( $row ), true ) ?? [];
        }

        return (array) $row;
    }

    /**
     * The attributes summed across "result" inside one report type's block of the component.
     *
     * @return array<int, string>
     */
    private function keysSummedInBlock( string $type ): array
    {
        $contents = file_get_contents( base_path( 'resources/ts/pages/dashboard/reports/ns-sale-report.vue' ) );

        $start = strpos( $contents, "reportType.value === '$type'" );

        $this->assertNotFalse( $start, sprintf( 'The component has no block for the %s report.', $type ) );

        /**
         * The block ends where the next report type begins, or at the end of the template.
         */
        $end = strlen( $contents );

        foreach ( [ 'categories_report', 'categories_summary', 'products_report' ] as $other ) {
            if ( $other === $type ) {
                continue;
            }

            $position = strpos( $contents, "reportType.value === '$other'" );

            if ( $position !== false && $position > $start && $position < $end ) {
                $end = $position;
            }
        }

        preg_match_all(
            "/computeTotal\(\s*result\s*,\s*'([a-z_]+)'\s*\)/",
            substr( $contents, $start, $end - $start ),
            $matches
        );

        return array_values( array_unique( $matches[ 1 ] ?? [] ) );
    }

    /**
     * total_price is stored already discounted, so any profit formula that subtracts the discount again
     * charges it to the shop twice. The two tables on this screen disagreed on that point, and the
     * categories table was the one that had it wrong.
     */
    public function test_the_screen_does_not_subtract_the_discount_from_profit(): void
    {
        $contents = file_get_contents( base_path( 'resources/ts/pages/dashboard/reports/ns-sale-report.vue' ) );

        /**
         * Only the body of the profit helper is examined. The discount is a legitimate column of its own
         * and is summed elsewhere on the screen; what must not happen is it being taken off the profit,
         * because total_price arrives already discounted.
         */
        $matched = preg_match( '/productProfit\(\s*product\s*\)\s*\{(.*?)\n        \},/s', $contents, $matches );

        $this->assertSame( 1, $matched, 'The productProfit helper was not found, so profit is no longer computed in one place.' );

        $this->assertStringNotContainsString(
            'discount',
            $matches[ 1 ],
            'The profit helper must not involve the discount, which total_price already accounts for.'
        );

        /**
         * Both tables have to route through the helper, or they can drift apart again the way they had.
         */
        $this->assertGreaterThanOrEqual(
            2,
            substr_count( $contents, 'productProfit( product )' ),
            'Each table showing a per line profit is expected to call the shared helper.'
        );

        $this->assertStringContainsString(
            'collectionProfit( result )',
            $contents,
            'The totals row is expected to reuse the same rule rather than restate it.'
        );
    }
}
