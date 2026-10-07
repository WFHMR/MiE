<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceSequence;
use App\Models\User;
use App\Models\VatRate;
use App\Services\ThaiTaxInvoiceService;
use App\Services\ThaiVatService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithCustomerTest;

/**
 * Covers issuing Thai tax invoices.
 *
 * A tax invoice is a legal document, so the properties under test are the ones an
 * auditor would check: the number is sequential and never reused, the VAT is shown
 * separately from the value of the goods, exempt and zero rated sales are not merged,
 * the document cannot be issued by a shop that is not registered, and a reprint
 * reproduces the document as issued rather than as the order looks today.
 */
class ThaiTaxInvoiceTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication, WithCustomerTest;

    private const VALID_TAX_ID = '0105536001107';

    private ThaiTaxInvoiceService $invoices;

    private ThaiVatService $vat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->invoices = app()->make( ThaiTaxInvoiceService::class );
        $this->vat = app()->make( ThaiVatService::class );

        ns()->option->set( 'ns_currency_precision', 2 );
        ns()->option->set( 'ns_thai_tax_invoice_prefix', 'TIV' );
        ns()->option->set( 'ns_thai_tax_invoice_start_number', 0 );

        $this->givenRegisteredShop();
        $this->givenOnlyRate( 7, '2020-01-01' );
    }

    public function test_an_unregistered_shop_cannot_issue_a_tax_invoice(): void
    {
        ns()->option->set( 'ns_thai_vat_registered', 'no' );

        $order = $this->givenPaidOrder();

        $this->expectException( NotAllowedException::class );

        /**
         * Below the 1.8M baht threshold a shop is not registered and may only issue a
         * receipt. A tax invoice would be unusable for the buyer's input tax claim.
         */
        $this->invoices->issue( $order );
    }

    public function test_an_unpaid_order_cannot_be_invoiced(): void
    {
        $order = $this->givenPaidOrder();
        $order->payment_status = Order::PAYMENT_UNPAID;
        $order->save();

        $this->expectException( NotAllowedException::class );

        $this->invoices->issue( $order );
    }

    public function test_issuing_produces_a_numbered_document(): void
    {
        $invoice = $this->invoices->issue( $this->givenPaidOrder() );

        $this->assertInstanceOf( TaxInvoice::class, $invoice );
        $this->assertSame( TaxInvoice::TYPE_ABBREVIATED, $invoice->type );
        $this->assertSame( TaxInvoice::STATUS_ISSUED, $invoice->status );
        $this->assertMatchesRegularExpression( '/^TIV-\d{4}-\d{6}$/', $invoice->number );
        $this->assertSame( self::VALID_TAX_ID, $invoice->seller_tax_id );
        $this->assertSame( '00000', $invoice->seller_branch_code );
    }

    public function test_numbers_are_sequential_and_never_reused(): void
    {
        $first = $this->invoices->issue( $this->givenPaidOrder() );
        $second = $this->invoices->issue( $this->givenPaidOrder() );
        $third = $this->invoices->issue( $this->givenPaidOrder() );

        $this->assertSame( 1, $first->sequence_number );
        $this->assertSame( 2, $second->sequence_number );
        $this->assertSame( 3, $third->sequence_number );

        /**
         * Cancelling must not free the number for reuse. Two documents sharing a number
         * on a filed return is not something that can be quietly corrected later.
         */
        $this->invoices->cancel( $second, 'ลูกค้าขอแก้ชื่อ' );
        $fourth = $this->invoices->issue( $this->givenPaidOrder() );

        $this->assertSame( 4, $fourth->sequence_number );
        $this->assertSame(
            3,
            TaxInvoice::where( 'sequence_scope', $first->sequence_scope )->where( 'status', TaxInvoice::STATUS_ISSUED )->count()
        );
    }

    public function test_the_same_order_cannot_be_invoiced_twice_with_the_same_document_type(): void
    {
        $order = $this->givenPaidOrder();

        $this->invoices->issue( $order, TaxInvoice::TYPE_ABBREVIATED );

        $this->expectException( NotAllowedException::class );

        /**
         * Two documents for one sale means the same output tax reported twice.
         */
        $this->invoices->issue( $order, TaxInvoice::TYPE_ABBREVIATED );
    }

    public function test_a_full_form_invoice_requires_the_buyer(): void
    {
        $order = $this->givenPaidOrder();

        $this->expectException( NotAllowedException::class );

        /**
         * Section 86/4 requires the buyer's name — without it the buyer cannot claim the
         * VAT, which is the only reason a full form invoice exists.
         */
        $this->invoices->issue( $order, TaxInvoice::TYPE_FULL );
    }

    public function test_a_full_form_invoice_records_the_buyer(): void
    {
        $invoice = $this->invoices->issue(
            order: $this->givenPaidOrder(),
            type: TaxInvoice::TYPE_FULL,
            buyer: [
                'name' => 'บริษัท ผู้ซื้อ จำกัด',
                'address' => '1 ถนนทดสอบ กรุงเทพมหานคร',
                'tax_id' => '0105536001107',
            ],
        );

        $this->assertSame( TaxInvoice::TYPE_FULL, $invoice->type );
        $this->assertSame( 'บริษัท ผู้ซื้อ จำกัด', $invoice->buyer_name );
        $this->assertSame( '0105536001107', $invoice->buyer_tax_id );
    }

    public function test_the_vat_is_separated_from_the_value_of_the_goods(): void
    {
        $order = $this->givenPaidOrder( grossTotal: 107.0 );
        $invoice = $this->invoices->issue( $order );

        /**
         * Thai shelf prices include VAT, so 107.00 collected at 7% is 100.00 of goods and
         * 7.00 of tax. Showing the two separately is the entire purpose of the document.
         */
        $this->assertEqualsWithDelta( 107.0, (float) $invoice->total_gross, 0.01 );
        $this->assertEqualsWithDelta( 100.0, (float) $invoice->total_net, 0.01 );
        $this->assertEqualsWithDelta( 7.0, (float) $invoice->total_vat, 0.01 );
        $this->assertEqualsWithDelta( 7.0, (float) $invoice->vat_rate, 0.001 );

        $this->assertEqualsWithDelta(
            (float) $invoice->total_gross,
            (float) $invoice->total_net + (float) $invoice->total_vat,
            0.01,
            'The goods value plus the tax must reconstruct the amount the customer paid.'
        );
    }

    public function test_an_exempt_sale_carries_no_tax_and_is_reported_separately(): void
    {
        $order = $this->givenPaidOrder( vatStatus: ThaiVatService::STATUS_EXEMPT, grossTotal: 107.0 );
        $invoice = $this->invoices->issue( $order );

        $this->assertEqualsWithDelta( 0.0, (float) $invoice->total_vat, 0.01 );
        $this->assertEqualsWithDelta( 107.0, (float) $invoice->total_exempt, 0.01 );
        $this->assertEqualsWithDelta( 0.0, (float) $invoice->total_zero_rated, 0.01 );
    }

    /**
     * Exempt and zero rated both collect no tax, which is exactly why they must not be
     * merged: an exempt supply sits outside the VAT system, a zero rated one inside it
     * at 0%, and the PP30 return reports them on separate lines.
     */
    public function test_a_zero_rated_sale_is_not_merged_with_an_exempt_one(): void
    {
        $order = $this->givenPaidOrder( vatStatus: ThaiVatService::STATUS_ZERO_RATED, grossTotal: 107.0 );
        $invoice = $this->invoices->issue( $order );

        $this->assertEqualsWithDelta( 0.0, (float) $invoice->total_vat, 0.01 );
        $this->assertEqualsWithDelta( 107.0, (float) $invoice->total_zero_rated, 0.01 );
        $this->assertEqualsWithDelta( 0.0, (float) $invoice->total_exempt, 0.01 );
    }

    public function test_the_document_keeps_its_own_copy_of_what_it_printed(): void
    {
        $order = $this->givenPaidOrder( grossTotal: 107.0 );
        $invoice = $this->invoices->issue( $order );

        $originalNet = (float) $invoice->total_net;
        $originalSeller = $invoice->seller_address;

        /**
         * The shop moves and the reduced rate is replaced. A document already handed to a
         * customer, and already reported on a return, must not change.
         */
        ns()->option->set( 'ns_thai_tax_registered_address', 'ที่อยู่ใหม่หลังย้ายร้าน' );
        $this->vat->openRatePeriod( rate: 10.0, from: '2020-01-01' );

        $reloaded = TaxInvoice::findOrFail( $invoice->id );

        $this->assertSame( $originalSeller, $reloaded->seller_address );
        $this->assertEqualsWithDelta( $originalNet, (float) $reloaded->total_net, 0.01 );
        $this->assertEqualsWithDelta( 7.0, (float) $reloaded->vat_rate, 0.001 );
        $this->assertNotEmpty( $reloaded->lines );
    }

    public function test_cancelling_requires_a_reason_and_keeps_the_document(): void
    {
        $invoice = $this->invoices->issue( $this->givenPaidOrder() );

        try {
            $this->invoices->cancel( $invoice, '   ' );
            $this->fail( 'A cancellation without a reason must be refused.' );
        } catch ( NotAllowedException ) {
            // expected
        }

        $cancelled = $this->invoices->cancel( $invoice, 'พิมพ์ชื่อผู้ซื้อผิด' );

        $this->assertSame( TaxInvoice::STATUS_CANCELLED, $cancelled->status );
        $this->assertNotNull( $cancelled->cancelled_at );
        $this->assertNotNull( TaxInvoice::find( $invoice->id ), 'A cancelled tax invoice is kept, never deleted.' );

        $this->expectException( NotAllowedException::class );
        $this->invoices->cancel( $cancelled->refresh(), 'ยกเลิกซ้ำ' );
    }

    public function test_the_series_resets_per_year_but_not_per_document(): void
    {
        $scope = $this->invoices->currentScope();

        $this->assertSame( 'TIV-' . ns()->date->getNow()->format( 'Y' ), $scope );
        $this->assertSame( 'TIV-2026-000042', $this->invoices->formatNumber( 'TIV-2026', 42 ) );

        $this->invoices->issue( $this->givenPaidOrder() );

        $this->assertSame( 1, TaxInvoiceSequence::where( 'scope', $scope )->firstOrFail()->last_number );
    }

    public function test_an_issued_invoice_is_reachable_and_shows_its_number(): void
    {
        $admin = Role::namespace( 'admin' )->users->first();
        $invoice = $this->invoices->issue( $this->givenPaidOrder() );

        $response = $this->actingAs( $admin )
            ->json( 'GET', '/dashboard/orders/tax-invoice/' . $invoice->id . '?dash-visibility=disabled' );

        $response->assertStatus( 200 );
        $response->assertSee( $invoice->number, false );
        $response->assertSee( 'ใบกำกับภาษีอย่างย่อ', false );

        /**
         * Section 86/6 requires an abbreviated invoice to say so, because it prints
         * VAT inclusive line amounts.
         */
        $response->assertSee( 'ราคารวมภาษีมูลค่าเพิ่มแล้ว', false );
    }

    /**
     * Under Thai VAT every establishment carries its own five digit code, and a tax invoice has to
     * show the code of the establishment that issued it. The code was read from one option shared by
     * the whole install, so a sale rung up at a branch printed the head office code on the buyer's
     * document — an error on a tax record, and one that grows with every branch opened.
     */
    public function test_the_invoice_shows_the_establishment_code_of_the_branch_that_issued_it(): void
    {
        $branchId = (int) DB::table( 'nexopos_branches' )->insertGetId( [
            'code' => 'TX' . uniqid(),
            'tax_branch_code' => '00007',
            'name' => 'สาขาทดสอบใบกำกับ',
            'address' => '77/7 ถนนสาขา จังหวัดทดสอบ',
            'is_main' => false,
            'is_active' => true,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $order = $this->givenPaidOrder();
        $order->branch_id = $branchId;
        $order->save();

        $invoice = $this->invoices->issue( $order );

        $this->assertSame(
            '00007',
            $invoice->seller_branch_code,
            'The document printed the head office code for a sale made at a branch.'
        );

        $this->assertSame(
            '77/7 ถนนสาขา จังหวัดทดสอบ',
            $invoice->seller_address,
            'The establishment address has to be the branch that issued the document.'
        );
    }

    /**
     * A branch with no code of its own keeps today's behaviour, so an install that has not filled
     * the codes in is unaffected.
     */
    public function test_a_branch_without_its_own_code_falls_back_to_the_shop_setting(): void
    {
        $branchId = (int) DB::table( 'nexopos_branches' )->insertGetId( [
            'code' => 'TX' . uniqid(),
            'tax_branch_code' => null,
            'name' => 'สาขาไม่ได้ใส่รหัส',
            'is_main' => false,
            'is_active' => true,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $order = $this->givenPaidOrder();
        $order->branch_id = $branchId;
        $order->save();

        $invoice = $this->invoices->issue( $order );

        $this->assertSame( '00000', $invoice->seller_branch_code );
    }

    /**
     * A code typed without its leading zeros still has to be printed as five digits.
     */
    public function test_a_short_branch_code_is_padded_to_five_digits(): void
    {
        $this->assertSame( '00001', app( ThaiVatService::class )->normaliseBranchCode( '1' ) );
        $this->assertSame( '00000', app( ThaiVatService::class )->normaliseBranchCode( '' ) );
        $this->assertSame( '00000', app( ThaiVatService::class )->normaliseBranchCode( null ) );
        $this->assertSame( '00012', app( ThaiVatService::class )->normaliseBranchCode( '12' ) );
    }

    private function givenRegisteredShop(): void
    {
        ns()->option->set( 'ns_thai_vat_registered', 'yes' );
        ns()->option->set( 'ns_thai_tax_id', self::VALID_TAX_ID );
        ns()->option->set( 'ns_thai_tax_registered_name', 'บริษัท ทดสอบภาษี จำกัด' );
        ns()->option->set( 'ns_thai_tax_registered_address', '99/1 ถนนทดสอบ กรุงเทพมหานคร' );
        ns()->option->set( 'ns_thai_tax_branch_code', '00000' );
    }

    private function givenOnlyRate( float $rate, string $from ): void
    {
        VatRate::query()->delete();

        VatRate::factory()->create( [
            'label' => 'VAT ' . $rate . '%',
            'rate' => $rate,
            'effective_from' => $from,
            'effective_to' => null,
        ] );
    }

    /**
     * A paid order with one line, at a known gross total and VAT classification.
     *
     * Built directly rather than through the API so the line's frozen vat_status and
     * vat_rate are exactly what the assertion needs, without depending on the POS
     * payload or the shop's current settings.
     */
    private function givenPaidOrder(
        string $vatStatus = ThaiVatService::STATUS_STANDARD,
        float $grossTotal = 107.0,
    ): Order {
        $unitQuantity = ProductUnitQuantity::whereHas( 'product', function ( $query ) {
            $query->where( 'type', '!=', Product::TYPE_GROUPED );
        } )->with( 'product' )->firstOrFail();

        $customer = Customer::first() ?: $this->attemptCreateCustomer();

        $order = new Order;
        $order->code = 'TEST-' . uniqid();
        $order->type = 'takeaway';
        $order->payment_status = Order::PAYMENT_PAID;
        $order->customer_id = $customer->id;
        $order->author_id = User::first()->id;
        $order->subtotal = $grossTotal;
        $order->total = $grossTotal;
        $order->tendered = $grossTotal;
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
