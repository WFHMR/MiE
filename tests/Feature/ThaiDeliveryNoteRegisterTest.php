<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Models\VatRate;
use App\Services\ReportService;
use App\Services\ThaiTaxInvoiceService;
use App\Services\ThaiVatService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Covers the two delivery note registers — รายงานการออกใบส่งสินค้า/บิลเงินสด and
 * รายงานการออกใบส่งสินค้า/ใบฝากสินค้าชั่วคราว.
 *
 * These are the shop's daily control sheets. The owner prints one at closing, adds up the
 * บิลเงินสด total and expects it to match the till; adds up the ใบฝากสินค้าชั่วคราว total
 * and expects it to match what went into the credit book. So the property under test is
 * the split: an order must appear on exactly one of the two, and the totals must be the
 * sum of what is printed above them.
 *
 * The columns are transcribed from หจก. รุ่งเรืองแกรนด์ registers 69080147–69080180 and
 * 69080283 onward, so the assertions are a specification of the shop's stationery.
 */
class ThaiDeliveryNoteRegisterTest extends TestCase
{
    use DatabaseTransactions;

    private ReportService $reports;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = app()->make( ReportService::class );
        $this->admin = Role::namespace( 'admin' )?->users()->first() ?? User::first();

        /**
         * Saving an order line writes a stock history row whose author_id is NOT NULL and
         * comes from the signed in user, so this reflects how the code actually runs.
         */
        $this->actingAs( $this->admin );

        ns()->option->set( 'ns_currency_precision', 2 );
        ns()->option->set( 'ns_thai_vat_registered', 'yes' );
        ns()->option->set( 'ns_thai_tax_id', '0223554000076' );
        ns()->option->set( 'ns_thai_tax_registered_name', 'ห้างหุ้นส่วนจำกัด รุ่งเรืองแกรนด์' );
        ns()->option->set( 'ns_thai_tax_branch_code', ThaiVatService::HEAD_OFFICE_BRANCH_CODE );
        ns()->option->set( 'ns_thai_tax_invoice_prefix', 'TIV' );
        ns()->option->set( 'ns_thai_tax_invoice_start_number', 0 );

        VatRate::query()->delete();

        VatRate::factory()->create( [
            'label' => 'VAT 7%',
            'rate' => 7,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
        ] );

        $this->customer = $this->givenCreditCustomer();
    }

    /**
     * The split is the whole point of having two sheets. A settled sale belongs on the cash
     * register because the money should be in the till; an unsettled one belongs on the
     * consignment register because the goods left against a promise. An order on both would
     * be counted twice, and one on neither would go missing at closing.
     */
    public function test_a_delivery_appears_on_exactly_one_of_the_two_registers(): void
    {
        $settled = $this->givenOrder( total: 1476.50, paymentStatus: Order::PAYMENT_PAID );
        $outstanding = $this->givenOrder( total: 18650.0, paymentStatus: Order::PAYMENT_UNPAID );

        $cash = $this->register( ReportService::DELIVERY_NOTES_CASH );
        $consignment = $this->register( ReportService::DELIVERY_NOTES_CONSIGNMENT );

        $this->assertContains( $settled->id, collect( $cash[ 'rows' ] )->pluck( 'order_id' ) );
        $this->assertNotContains( $outstanding->id, collect( $cash[ 'rows' ] )->pluck( 'order_id' ) );

        $this->assertContains( $outstanding->id, collect( $consignment[ 'rows' ] )->pluck( 'order_id' ) );
        $this->assertNotContains( $settled->id, collect( $consignment[ 'rows' ] )->pluck( 'order_id' ) );
    }

    public function test_each_row_carries_the_columns_the_paper_register_has(): void
    {
        $order = $this->givenOrder( total: 2602.0, paymentStatus: Order::PAYMENT_PAID );

        $row = collect( $this->register( ReportService::DELIVERY_NOTES_CASH )[ 'rows' ] )
            ->firstWhere( 'order_id', $order->id );

        $this->assertNotNull( $row, 'The settled order has to be on the cash register.' );

        $this->assertSame( ns()->date->getNow()->toDateString(), $row[ 'delivered_at' ] );
        $this->assertSame( $order->code, $row[ 'document_number' ] );
        $this->assertSame( (string) $this->customer->id, $row[ 'customer_code' ] );
        $this->assertNotSame( '', $row[ 'customer_name' ] );
        $this->assertSame( 15, $row[ 'payment_term_days' ] );
        $this->assertEqualsWithDelta( 2602.0, (float) $row[ 'total' ], 0.01 );
    }

    /**
     * The register has one tax column, and the shop reads across it to see the tax it
     * collected on the day. 2,140 collected at 7% is 2,000 of goods and 140 of tax.
     */
    public function test_the_tax_column_separates_the_tax_from_the_value_of_the_goods(): void
    {
        $order = $this->givenOrder( total: 2140.0, paymentStatus: Order::PAYMENT_PAID );

        $row = collect( $this->register( ReportService::DELIVERY_NOTES_CASH )[ 'rows' ] )
            ->firstWhere( 'order_id', $order->id );

        $this->assertEqualsWithDelta( 2000.0, (float) $row[ 'net' ], 0.01 );
        $this->assertEqualsWithDelta( 140.0, (float) $row[ 'tax' ], 0.01 );
        $this->assertEqualsWithDelta( 2140.0, (float) $row[ 'total' ], 0.01 );

        $this->assertEqualsWithDelta(
            (float) $row[ 'total' ],
            (float) $row[ 'net' ] + (float) $row[ 'tax' ],
            0.01,
            'The goods value plus the tax has to reconstruct what the customer paid.'
        );
    }

    /**
     * The shop sells VAT exempt goods, which is why every tax cell on their own registers
     * reads 0.00 while the net and total columns match. An exempt line must not be pushed
     * through the 7% split, or the register would invent a tax the shop never collected.
     */
    public function test_an_exempt_delivery_shows_no_tax_and_a_net_equal_to_the_total(): void
    {
        $order = $this->givenOrder(
            total: 870.0,
            paymentStatus: Order::PAYMENT_PAID,
            vatStatus: ThaiVatService::STATUS_EXEMPT,
        );

        $row = collect( $this->register( ReportService::DELIVERY_NOTES_CASH )[ 'rows' ] )
            ->firstWhere( 'order_id', $order->id );

        $this->assertEqualsWithDelta( 0.0, (float) $row[ 'tax' ], 0.01 );
        $this->assertEqualsWithDelta( 870.0, (float) $row[ 'net' ], 0.01 );
        $this->assertEqualsWithDelta( 870.0, (float) $row[ 'total' ], 0.01 );
    }

    /**
     * The closing figures are what the sheet is read for, so they have to be the sum of
     * what is printed above them rather than a separately computed aggregate that could
     * disagree with the rows.
     */
    public function test_the_totals_are_the_sum_of_the_printed_rows(): void
    {
        $this->givenOrder( total: 1476.50, paymentStatus: Order::PAYMENT_PAID );
        $this->givenOrder( total: 870.0, paymentStatus: Order::PAYMENT_PAID );
        $this->givenOrder( total: 2602.0, paymentStatus: Order::PAYMENT_PAID );

        $register = $this->register( ReportService::DELIVERY_NOTES_CASH );

        $rows = collect( $register[ 'rows' ] );

        $this->assertSame( $rows->count(), $register[ 'totals' ][ 'documents' ] );
        $this->assertEqualsWithDelta( $rows->sum( 'net' ), $register[ 'totals' ][ 'net' ], 0.02 );
        $this->assertEqualsWithDelta( $rows->sum( 'tax' ), $register[ 'totals' ][ 'tax' ], 0.02 );
        $this->assertEqualsWithDelta( $rows->sum( 'total' ), $register[ 'totals' ][ 'total' ], 0.02 );
    }

    /**
     * A withdrawn document is not on the register. Listing it would make the total disagree
     * with the till by exactly the amount that was never taken, which is the hardest kind
     * of discrepancy to explain at closing.
     */
    public function test_held_voided_and_refunded_deliveries_are_left_off(): void
    {
        $excluded = collect( [ Order::PAYMENT_HOLD, Order::PAYMENT_VOID, Order::PAYMENT_REFUNDED ] )
            ->map( fn ( string $status ) => $this->givenOrder( total: 500.0, paymentStatus: $status )->id );

        $listed = collect( $this->register( ReportService::DELIVERY_NOTES_CASH )[ 'rows' ] )
            ->merge( $this->register( ReportService::DELIVERY_NOTES_CONSIGNMENT )[ 'rows' ] )
            ->pluck( 'order_id' );

        foreach ( $excluded as $orderId ) {
            $this->assertNotContains( $orderId, $listed );
        }
    }

    /**
     * The เลขที่ใบส่งฯ column is the reference the customer quotes back. Once a full form
     * invoice has been issued that is the number on the copy they filed, so the register
     * has to show it rather than the internal order code.
     */
    public function test_an_issued_invoice_number_replaces_the_order_code(): void
    {
        $order = $this->givenOrder( total: 3362.0, paymentStatus: Order::PAYMENT_PAID );

        $invoice = app()->make( ThaiTaxInvoiceService::class )->issue(
            order: $order,
            type: TaxInvoice::TYPE_FULL,
            buyer: [ 'name' => 'ร้านกาแฟทันที', 'address' => 'จันทบุรี', 'tax_id' => '3430300893753' ],
            authorId: $this->admin->id,
        );

        $row = collect( $this->register( ReportService::DELIVERY_NOTES_CASH )[ 'rows' ] )
            ->firstWhere( 'order_id', $order->id );

        $this->assertSame( $invoice->number, $row[ 'document_number' ] );
    }

    /**
     * The register is a control sheet for the documents it lists, so its figures have to be
     * the documents' own — to the satang. Recomputing the split would round the group where
     * the invoice rounded each line, and the two would disagree by a satang on some amounts.
     * A control sheet that does not tie out to what the customer is holding is worse than
     * none, because it sends someone looking for an error that is not there.
     */
    public function test_an_invoiced_row_ties_out_to_the_invoice_exactly(): void
    {
        $order = $this->givenOrder( total: 9951.0, paymentStatus: Order::PAYMENT_PAID );

        $invoice = app()->make( ThaiTaxInvoiceService::class )->issue(
            order: $order,
            type: TaxInvoice::TYPE_FULL,
            buyer: [ 'name' => 'ร้านโชคชัยพานิช', 'address' => 'จันทบุรี', 'tax_id' => '3430300893753' ],
            authorId: $this->admin->id,
        );

        $row = collect( $this->register( ReportService::DELIVERY_NOTES_CASH )[ 'rows' ] )
            ->firstWhere( 'order_id', $order->id );

        $this->assertSame( (float) $invoice->total_net, (float) $row[ 'net' ] );
        $this->assertSame( (float) $invoice->total_vat, (float) $row[ 'tax' ] );
        $this->assertSame( (float) $invoice->total_gross, (float) $row[ 'total' ] );
    }

    /**
     * An exempt sale is invoiced with a net of zero and its value in total_exempt. The
     * register has one net column, so folding the untaxed buckets in is what keeps the row
     * from printing a net of zero beside a total of the full amount.
     */
    public function test_an_invoiced_exempt_row_shows_its_value_in_the_net_column(): void
    {
        $order = $this->givenOrder(
            total: 870.0,
            paymentStatus: Order::PAYMENT_PAID,
            vatStatus: ThaiVatService::STATUS_EXEMPT,
        );

        app()->make( ThaiTaxInvoiceService::class )->issue(
            order: $order,
            type: TaxInvoice::TYPE_FULL,
            buyer: [ 'name' => 'ร้านข้าวยำ', 'address' => 'จันทบุรี', 'tax_id' => '3430300893753' ],
            authorId: $this->admin->id,
        );

        $row = collect( $this->register( ReportService::DELIVERY_NOTES_CASH )[ 'rows' ] )
            ->firstWhere( 'order_id', $order->id );

        $this->assertEqualsWithDelta( 870.0, (float) $row[ 'net' ], 0.01 );
        $this->assertEqualsWithDelta( 0.0, (float) $row[ 'tax' ], 0.01 );
        $this->assertEqualsWithDelta( 870.0, (float) $row[ 'total' ], 0.01 );
    }

    public function test_deliveries_outside_the_period_are_left_off(): void
    {
        $order = $this->givenOrder( total: 4627.0, paymentStatus: Order::PAYMENT_PAID );

        $order->created_at = ns()->date->getNow()->subDays( 10 )->toDateTimeString();
        $order->save();

        $register = $this->register( ReportService::DELIVERY_NOTES_CASH );

        $this->assertNotContains( $order->id, collect( $register[ 'rows' ] )->pluck( 'order_id' ) );
    }

    public function test_each_register_states_which_document_it_is(): void
    {
        $cash = $this->register( ReportService::DELIVERY_NOTES_CASH );
        $consignment = $this->register( ReportService::DELIVERY_NOTES_CONSIGNMENT );

        $this->assertSame( 'รายงานการออกใบส่งสินค้า/บิลเงินสด', $cash[ 'title' ] );
        $this->assertSame( ReportService::DELIVERY_NOTES_CASH, $cash[ 'type' ] );

        $this->assertSame( 'รายงานการออกใบส่งสินค้า/ใบฝากสินค้าชั่วคราว', $consignment[ 'title' ] );
        $this->assertSame( ReportService::DELIVERY_NOTES_CONSIGNMENT, $consignment[ 'type' ] );

        // The printed heading needs the period and the print date the paper form carries.
        $this->assertNotEmpty( $cash[ 'period' ][ 'start' ] );
        $this->assertNotEmpty( $cash[ 'period' ][ 'end' ] );
        $this->assertNotEmpty( $cash[ 'period' ][ 'printed_at' ] );

        $this->assertNotEmpty( $cash[ 'notes' ] );
    }

    /**
     * An unrecognised type must not silently widen the register to every order. Falling back
     * to the cash sheet keeps the two sets disjoint whatever arrives from the browser.
     */
    public function test_an_unknown_register_type_falls_back_to_the_cash_sheet(): void
    {
        $this->givenOrder( total: 553.0, paymentStatus: Order::PAYMENT_PAID );
        $outstanding = $this->givenOrder( total: 553.0, paymentStatus: Order::PAYMENT_UNPAID );

        $register = $this->register( 'something-else' );

        $this->assertSame( ReportService::DELIVERY_NOTES_CASH, $register[ 'type' ] );
        $this->assertNotContains( $outstanding->id, collect( $register[ 'rows' ] )->pluck( 'order_id' ) );
    }

    public function test_an_empty_period_still_produces_a_printable_register(): void
    {
        $register = $this->reports->getDeliveryNoteIssuanceReport(
            ns()->date->getNow()->subYears( 5 )->startOfDay()->toDateTimeString(),
            ns()->date->getNow()->subYears( 5 )->endOfDay()->toDateTimeString(),
            ReportService::DELIVERY_NOTES_CASH,
        );

        $this->assertSame( [], $register[ 'rows' ] );
        $this->assertSame( 0, $register[ 'totals' ][ 'documents' ] );
        $this->assertEqualsWithDelta( 0.0, $register[ 'totals' ][ 'total' ], 0.01 );
        $this->assertNotEmpty( $register[ 'title' ] );
        $this->assertCount( 3, $register[ 'summary' ] );
    }

    /**
     * Two routes onto one Blade. The risk of sharing the template is that one page renders
     * with the other's register type and quietly shows the wrong set of orders, so the type
     * handed to the component is asserted rather than just the status code.
     */
    public function test_both_pages_render_with_their_own_register_type(): void
    {
        $cash = $this->actingAs( $this->admin )->get( '/dashboard/reports/delivery-notes/cash' );

        $cash->assertStatus( 200 );
        $cash->assertSee( 'รายงานการออกใบส่งสินค้า/บิลเงินสด', false );
        $cash->assertSee( 'registerType="' . ReportService::DELIVERY_NOTES_CASH . '"', false );

        $consignment = $this->actingAs( $this->admin )->get( '/dashboard/reports/delivery-notes/consignment' );

        $consignment->assertStatus( 200 );
        $consignment->assertSee( 'รายงานการออกใบส่งสินค้า/ใบฝากสินค้าชั่วคราว', false );
        $consignment->assertSee( 'registerType="' . ReportService::DELIVERY_NOTES_CONSIGNMENT . '"', false );

        /**
         * TestOtherGetRoutes walks every parameterless dashboard GET route and requires a
         * 200 carrying this marker, so a page that renders but does not extend the
         * dashboard layout fails there instead of here — a long way from the cause.
         */
        $cash->assertSee( 'dashboard-body', false );
        $consignment->assertSee( 'dashboard-body', false );
    }

    public function test_the_register_is_reachable_over_the_api_and_gated(): void
    {
        $this->givenOrder( total: 1657.0, paymentStatus: Order::PAYMENT_PAID );

        $response = $this->actingAs( $this->admin )->json( 'POST', '/api/reports/delivery-notes', [
            'startDate' => ns()->date->getNow()->startOfDay()->toDateTimeString(),
            'endDate' => ns()->date->getNow()->endOfDay()->toDateTimeString(),
            'type' => ReportService::DELIVERY_NOTES_CASH,
        ] );

        $response->assertStatus( 200 );
        $response->assertJsonStructure( [
            'period' => [ 'start', 'end', 'printed_at' ],
            'type',
            'title',
            'rows',
            'summary',
            'totals' => [ 'documents', 'net', 'tax', 'total' ],
            'notes',
        ] );
    }

    /**
     * @return array<string, mixed>
     */
    private function register( string $type ): array
    {
        return $this->reports->getDeliveryNoteIssuanceReport(
            ns()->date->getNow()->startOfDay()->toDateTimeString(),
            ns()->date->getNow()->endOfDay()->toDateTimeString(),
            $type,
        );
    }

    /**
     * A customer on a 15 day term, which is what every row on the shop's own registers
     * shows in the กำหนดชำระ column.
     */
    private function givenCreditCustomer(): Customer
    {
        $group = CustomerGroup::first() ?: CustomerGroup::factory()->create();

        $group->payment_term_days = 15;
        $group->save();

        return Customer::factory()->create( [ 'group_id' => $group->id ] );
    }

    private function givenOrder(
        float $total,
        string $paymentStatus,
        string $vatStatus = ThaiVatService::STATUS_STANDARD,
    ): Order {
        $unitQuantity = ProductUnitQuantity::whereHas( 'product', function ( $query ) {
            $query->where( 'type', '!=', Product::TYPE_GROUPED );
        } )->with( 'product' )->firstOrFail();

        $order = new Order;
        $order->code = 'DNR-' . uniqid();
        $order->type = 'delivery';
        $order->payment_status = $paymentStatus;
        $order->customer_id = $this->customer->id;
        $order->author_id = $this->admin->id;
        $order->subtotal = $total;
        $order->total = $total;
        $order->tendered = $paymentStatus === Order::PAYMENT_PAID ? $total : 0;
        $order->created_at = ns()->date->getNow()->toDateTimeString();
        $order->save();

        /**
         * NsModel declares no $fillable, so every model is totally guarded and mass
         * assignment is refused. forceFill is how a line gets built outside OrdersService.
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
            'unit_price' => $total,
            'total_price' => $total,
            'status' => 'sold',
            'vat_status' => $vatStatus,
            'vat_rate' => $vatStatus === ThaiVatService::STATUS_STANDARD ? 7 : 0,
        ] );

        $order->products()->save( $orderProduct );

        return $order->fresh( 'products' );
    }
}
