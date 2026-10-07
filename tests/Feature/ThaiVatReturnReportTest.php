<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\User;
use App\Models\VatRate;
use App\Services\ReportService;
use App\Services\ThaiVatService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\WithCustomerTest;

/**
 * Covers the monthly Thai VAT return figures (ภ.พ.30).
 *
 * The return separates sales three ways and the split has to be right, because the
 * two untaxed categories are not interchangeable: an exempt supply is outside the VAT
 * system under Section 81, a zero rated one is a taxable supply charged at 0%. Both
 * produce no output tax, so a report driven by tax amounts alone would merge them and
 * understate the taxable turnover.
 */
class ThaiVatReturnReportTest extends TestCase
{
    use DatabaseTransactions, WithCustomerTest;

    private ReportService $reports;

    private string $periodStart;

    private string $periodEnd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = app()->make( ReportService::class );

        ns()->option->set( 'ns_currency_precision', 2 );

        VatRate::query()->delete();
        VatRate::factory()->create( [
            'label' => 'VAT 7%',
            'rate' => 7,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
        ] );

        $this->periodStart = ns()->date->getNow()->startOfMonth()->toDateTimeString();
        $this->periodEnd = ns()->date->getNow()->endOfMonth()->toDateTimeString();
    }

    public function test_the_period_defaults_to_the_current_month(): void
    {
        $report = $this->reports->getVatReturnReport();

        $this->assertSame( $this->periodStart, $report[ 'period' ][ 'start' ] );
        $this->assertSame( $this->periodEnd, $report[ 'period' ][ 'end' ] );
        $this->assertSame( 7.0, $report[ 'period' ][ 'rate' ] );
    }

    public function test_a_standard_rated_sale_produces_output_tax(): void
    {
        $before = $this->reports->getVatReturnReport( $this->periodStart, $this->periodEnd );

        $this->givenPaidSale( ThaiVatService::STATUS_STANDARD, 107.0 );

        $after = $this->reports->getVatReturnReport( $this->periodStart, $this->periodEnd );

        $this->assertEqualsWithDelta(
            $before[ 'output_tax' ] + 7.0,
            $after[ 'output_tax' ],
            0.02,
            '107.00 collected at 7% adds 7.00 of output tax.'
        );

        $this->assertEqualsWithDelta(
            $before[ 'standard_net' ] + 100.0,
            $after[ 'standard_net' ],
            0.02
        );
    }

    public function test_exempt_and_zero_rated_sales_are_reported_apart(): void
    {
        $before = $this->reports->getVatReturnReport( $this->periodStart, $this->periodEnd );

        $this->givenPaidSale( ThaiVatService::STATUS_EXEMPT, 50.0 );
        $this->givenPaidSale( ThaiVatService::STATUS_ZERO_RATED, 30.0 );

        $after = $this->reports->getVatReturnReport( $this->periodStart, $this->periodEnd );

        $this->assertEqualsWithDelta( $before[ 'exempt' ] + 50.0, $after[ 'exempt' ], 0.02 );
        $this->assertEqualsWithDelta( $before[ 'zero_rated' ] + 30.0, $after[ 'zero_rated' ], 0.02 );

        /**
         * Neither adds tax, which is precisely why they need separate lines.
         */
        $this->assertEqualsWithDelta( $before[ 'output_tax' ], $after[ 'output_tax' ], 0.02 );
    }

    public function test_a_refunded_line_is_excluded(): void
    {
        $order = $this->givenPaidSale( ThaiVatService::STATUS_STANDARD, 107.0 );
        $withSale = $this->reports->getVatReturnReport( $this->periodStart, $this->periodEnd );

        $order->products()->update( [ 'status' => 'refunded' ] );

        $afterRefund = $this->reports->getVatReturnReport( $this->periodStart, $this->periodEnd );

        $this->assertEqualsWithDelta(
            $withSale[ 'output_tax' ] - 7.0,
            $afterRefund[ 'output_tax' ],
            0.02,
            'A refunded line was reversed, so it produced no output tax.'
        );
    }

    public function test_an_unpaid_order_is_excluded(): void
    {
        $before = $this->reports->getVatReturnReport( $this->periodStart, $this->periodEnd );

        $order = $this->givenPaidSale( ThaiVatService::STATUS_STANDARD, 107.0 );
        $order->payment_status = Order::PAYMENT_UNPAID;
        $order->save();

        $after = $this->reports->getVatReturnReport( $this->periodStart, $this->periodEnd );

        $this->assertEqualsWithDelta( $before[ 'output_tax' ], $after[ 'output_tax' ], 0.02 );
    }

    public function test_a_sale_outside_the_period_is_excluded(): void
    {
        $before = $this->reports->getVatReturnReport( $this->periodStart, $this->periodEnd );

        $order = $this->givenPaidSale( ThaiVatService::STATUS_STANDARD, 107.0 );
        $order->created_at = ns()->date->getNow()->subMonths( 3 )->toDateTimeString();
        $order->save();

        $after = $this->reports->getVatReturnReport( $this->periodStart, $this->periodEnd );

        $this->assertEqualsWithDelta( $before[ 'output_tax' ], $after[ 'output_tax' ], 0.02 );
    }

    public function test_the_report_states_that_input_tax_is_not_tracked(): void
    {
        $report = $this->reports->getVatReturnReport( $this->periodStart, $this->periodEnd );

        $this->assertNotEmpty( $report[ 'notes' ] );

        /**
         * The return has a purchase side this system cannot fill, and an accountant who
         * does not know that would file an incomplete return. Saying so is part of the
         * report, not documentation.
         */
        $this->assertStringContainsString(
            'ภาษีซื้อ',
            implode( ' ', $report[ 'notes' ] )
        );
    }

    public function test_the_summary_is_shaped_like_every_other_report(): void
    {
        $report = $this->reports->getVatReturnReport( $this->periodStart, $this->periodEnd );

        $this->assertIsArray( $report[ 'summary' ] );
        $this->assertCount( 4, $report[ 'summary' ] );

        foreach ( $report[ 'summary' ] as $row ) {
            $this->assertArrayHasKey( 'label', $row );
            $this->assertArrayHasKey( 'total', $row );
        }
    }

    public function test_the_report_page_and_endpoint_are_reachable(): void
    {
        $admin = Role::namespace( 'admin' )->users->first();

        $this->actingAs( $admin )
            ->json( 'GET', '/dashboard/reports/vat-return' )
            ->assertStatus( 200 );

        Sanctum::actingAs( $admin, [ '*' ] );

        $this->json( 'POST', '/api/reports/vat-return', [
            'startDate' => $this->periodStart,
            'endDate' => $this->periodEnd,
        ] )
            ->assertStatus( 200 )
            ->assertJsonStructure( [ 'period', 'summary', 'output_tax', 'total_sales', 'invoices', 'notes' ] );
    }

    private function givenPaidSale( string $vatStatus, float $grossTotal ): Order
    {
        $unitQuantity = ProductUnitQuantity::whereHas( 'product', function ( $query ) {
            $query->where( 'type', '!=', Product::TYPE_GROUPED );
        } )->with( 'product' )->firstOrFail();

        $customer = Customer::first() ?: $this->attemptCreateCustomer();

        $order = new Order;
        $order->code = 'VATTEST-' . uniqid();
        $order->type = 'takeaway';
        $order->payment_status = Order::PAYMENT_PAID;
        $order->customer_id = $customer->id;
        $order->author_id = User::first()->id;
        $order->subtotal = $grossTotal;
        $order->total = $grossTotal;
        $order->created_at = ns()->date->getNow()->toDateTimeString();
        $order->save();

        /**
         * NsModel declares no $fillable, so every model in the app is totally guarded and
         * mass assignment is refused outright. forceFill is how a line gets built outside
         * of OrdersService, which assigns each attribute by hand.
         */
        $orderProduct = new OrderProduct;
        $orderProduct->forceFill( [
            'name' => $unitQuantity->product->name,
            'product_id' => $unitQuantity->product_id,
            'unit_id' => $unitQuantity->unit_id,
            'unit_quantity_id' => $unitQuantity->id,
            'product_category_id' => $unitQuantity->product->category_id ?? 0,
            'unit_name' => 'ชิ้น',
            'quantity' => 1,
            'unit_price' => $grossTotal,
            'total_price' => $grossTotal,
            'status' => 'sold',
            'vat_status' => $vatStatus,
            'vat_rate' => $vatStatus === ThaiVatService::STATUS_STANDARD ? 7 : 0,
        ] );

        $order->products()->save( $orderProduct );

        return $order->fresh( 'products' );
    }
}
