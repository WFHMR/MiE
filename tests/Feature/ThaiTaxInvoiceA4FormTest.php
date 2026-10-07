<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Models\VatRate;
use App\Services\ThaiTaxInvoiceService;
use App\Services\ThaiVatService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithCustomerTest;

/**
 * The full form tax invoice is printed on A4 stationery and handed to a buyer who will
 * file it and claim the VAT on it as input tax. It has to carry the fields the Revenue
 * Department requires and the ones the shop's staff read by position — the number top
 * right, the buyer block top left, the VAT split bottom right.
 *
 * The layout is transcribed from หจก. รุ่งเรืองแกรนด์ invoice 69080085, so these
 * assertions are a specification rather than a guess at what a Thai invoice looks like.
 *
 * The abbreviated form is deliberately checked here too: the two types were split into
 * separate templates, and the risk of that split is that one of them silently starts
 * rendering the other's layout.
 */
class ThaiTaxInvoiceA4FormTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication, WithCustomerTest;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Role::namespace( 'admin' )?->users()->first() ?? User::first();

        ns()->option->set( 'ns_currency_precision', 2 );
        ns()->option->set( 'ns_thai_tax_invoice_prefix', 'TIV' );
        ns()->option->set( 'ns_thai_tax_invoice_start_number', 0 );

        /**
         * The service refuses to issue when the shop is not VAT registered, which is the
         * correct behaviour and would otherwise make every case here fail at setup.
         */
        ns()->option->set( 'ns_thai_vat_registered', 'yes' );
        ns()->option->set( 'ns_thai_tax_id', '0223554000076' );
        ns()->option->set( 'ns_thai_tax_registered_name', 'ห้างหุ้นส่วนจำกัด รุ่งเรืองแกรนด์' );
        ns()->option->set( 'ns_thai_tax_registered_address', "31/62 หมู่ที่ 7 ตำบลจันทนิมิต อำเภอเมือง\nจังหวัดจันทบุรี 22000" );
        ns()->option->set( 'ns_thai_tax_branch_code', ThaiVatService::HEAD_OFFICE_BRANCH_CODE );
        ns()->option->set( 'ns_store_phone', '039-324078' );

        /**
         * A single open rate period, so the tax on the document is 7% of the goods and
         * not whatever the fixture happens to have left behind.
         */
        VatRate::query()->delete();

        VatRate::factory()->create( [
            'label' => 'VAT 7%',
            'rate' => 7,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
        ] );
    }

    public function test_the_full_form_prints_the_a4_document(): void
    {
        $invoice = $this->issueFullInvoice();

        $response = $this->actingAs( $this->admin )
            ->get( '/dashboard/orders/tax-invoice/' . $invoice->id . '?dash-visibility=disabled' );

        $response->assertStatus( 200 );

        /**
         * The combined title is the whole point of this sheet: one document that serves as
         * delivery note, tax invoice and receipt, filed by the recipient under whichever
         * applies to them.
         */
        $response->assertSee( 'ใบส่งสินค้า/ใบกำกับภาษี/ใบเสร็จรับเงิน', false );
        $response->assertSee( 'DELIVERY/INVOICE/RECEIPT', false );

        /**
         * A4 geometry. The application had no @page rule anywhere before this document, so
         * its absence would silently print the form at whatever size the browser defaults
         * to and it would not line up with the shop's stationery.
         */
        $response->assertSee( 'size: A4 portrait', false );
    }

    public function test_it_prints_the_fields_the_revenue_code_requires(): void
    {
        $invoice = $this->issueFullInvoice();

        $response = $this->actingAs( $this->admin )
            ->get( '/dashboard/orders/tax-invoice/' . $invoice->id . '?dash-visibility=disabled' );

        $response->assertStatus( 200 );

        // Seller registration — section 86/4.
        $response->assertSee( $invoice->seller_tax_id, false );
        $response->assertSee( 'เลขประจำตัวผู้เสียภาษี', false );
        $response->assertSee( 'สาขาที่ออกใบกำกับภาษี', false );
        $response->assertSee( 'สำนักงานใหญ่', false );

        // Buyer registration, without which the buyer cannot claim the input tax.
        $response->assertSee( 'ร้านโชคชัยพานิช', false );
        $response->assertSee( '3430300893753', false );

        // The document's own running number, not the order code.
        $response->assertSee( $invoice->number, false );

        // The tax shown as its own amount, which is the reason the document exists.
        $response->assertSee( 'มูลค่าสินค้าคิดภาษีมูลค่าเพิ่ม', false );
        $response->assertSee( 'ภาษีมูลค่าเพิ่ม', false );
        $response->assertSee( 'จำนวนเงินรวมสุทธิ', false );
    }

    /**
     * A figure can have a digit added to it after the fact, a sentence cannot, which is
     * why the written amount sits beside the total on every Thai invoice.
     */
    public function test_it_prints_the_total_in_thai_words(): void
    {
        $invoice = $this->issueFullInvoice();

        $response = $this->actingAs( $this->admin )
            ->get( '/dashboard/orders/tax-invoice/' . $invoice->id . '?dash-visibility=disabled' );

        $response->assertStatus( 200 );

        $expected = app()->make( \App\Services\ThaiBahtTextService::class )
            ->convert( $invoice->total_gross );

        $response->assertSee( $expected, false );
    }

    /**
     * The sheet is three documents at once, so it needs three acknowledgements: the buyer
     * confirms the goods, the driver confirms the delivery, the cashier confirms the money.
     * The previous full form carried only two.
     */
    public function test_it_carries_the_three_acknowledgements_the_paper_form_has(): void
    {
        $invoice = $this->issueFullInvoice();

        $response = $this->actingAs( $this->admin )
            ->get( '/dashboard/orders/tax-invoice/' . $invoice->id . '?dash-visibility=disabled' );

        $response->assertStatus( 200 );

        $response->assertSee( 'ได้รับสินค้าดังกล่าวตามรายการข้างต้นไว้เป็นที่เรียบร้อยแล้ว', false );
        $response->assertSee( 'ผู้รับสินค้า', false );
        $response->assertSee( 'ผู้ส่งสินค้า', false );
        $response->assertSee( 'ผู้รับเงิน/Receipt by', false );
    }

    /**
     * The V and N marks are the shop's own legend, and staff scan that column to check a
     * delivery against a purchase order.
     */
    public function test_it_marks_each_line_with_its_vat_treatment(): void
    {
        $invoice = $this->issueFullInvoice();

        $response = $this->actingAs( $this->admin )
            ->get( '/dashboard/orders/tax-invoice/' . $invoice->id . '?dash-visibility=disabled' );

        $response->assertStatus( 200 );

        $response->assertSee( 'ภาษี', false );
        $response->assertSee( 'V - สินค้าคิดภาษีมูลค่าเพิ่ม', false );
        $response->assertSee( 'N - สินค้ายกเว้นภาษีมูลค่าเพิ่ม', false );
        $response->assertSee( 'จำนวนหน่วย', false );
    }

    /**
     * The header fields the original table could not hold. Reading them from the live
     * order at print time would break the rule the whole document rests on: a reprint has
     * to reproduce what the customer was handed, and a customer can be renamed and their
     * credit terms renegotiated afterwards.
     */
    public function test_the_header_fields_are_frozen_onto_the_document(): void
    {
        if ( ! Schema::hasColumn( 'nexopos_tax_invoices', 'payment_terms' ) ) {
            $this->markTestSkipped( 'This database has not had the document header columns added yet.' );
        }

        $invoice = $this->issueFullInvoice();

        $this->assertNotNull( $invoice->payment_terms, 'The payment terms have to be captured at issue time.' );
        $this->assertSame( '039-324078', $invoice->seller_phone );

        $response = $this->actingAs( $this->admin )
            ->get( '/dashboard/orders/tax-invoice/' . $invoice->id . '?dash-visibility=disabled' );

        $response->assertSee( 'เงื่อนไขการชำระเงิน', false );
        $response->assertSee( 'กำหนดชำระเงิน', false );
        $response->assertSee( $invoice->payment_terms, false );
    }

    /**
     * The two types were split into separate templates. The risk of that split is one of
     * them quietly rendering the other's layout, which would put an A4 form on the thermal
     * roll or hand a counter customer a document with a buyer block on it.
     */
    public function test_the_abbreviated_form_still_prints_on_the_thermal_roll(): void
    {
        $order = $this->paidOrder();

        $invoice = app()->make( ThaiTaxInvoiceService::class )->issue(
            order: $order,
            type: TaxInvoice::TYPE_ABBREVIATED,
            authorId: $this->admin->id,
        );

        $response = $this->actingAs( $this->admin )
            ->get( '/dashboard/orders/tax-invoice/' . $invoice->id . '?dash-visibility=disabled' );

        $response->assertStatus( 200 );

        $response->assertSee( 'ใบกำกับภาษีอย่างย่อ', false );
        $response->assertSee( 'ราคารวมภาษีมูลค่าเพิ่มแล้ว', false );

        // The A4 form's markers must not appear on a 58mm receipt.
        $response->assertDontSee( 'size: A4 portrait', false );
        $response->assertDontSee( 'DELIVERY/INVOICE/RECEIPT', false );
    }

    private function issueFullInvoice(): TaxInvoice
    {
        return app()->make( ThaiTaxInvoiceService::class )->issue(
            order: $this->paidOrder(),
            type: TaxInvoice::TYPE_FULL,
            buyer: [
                'name' => 'ร้านโชคชัยพานิช',
                'address' => '38/13 ถ.อัมพวา ต.วัดใหม่ อ.เมือง จ. จันทบุรี 22000',
                'tax_id' => '3430300893753',
                'branch' => 'สำนักงานใหญ่',
            ],
            authorId: $this->admin->id,
        );
    }

    /**
     * A paid order with one line, built here rather than looked up, so the document under
     * test has a known total and a known VAT classification. A tax invoice can only be
     * issued against a settled sale, because at the counter the tax point is payment.
     */
    private function paidOrder(): Order
    {
        $unitQuantity = ProductUnitQuantity::whereHas( 'product', function ( $query ) {
            $query->where( 'type', '!=', Product::TYPE_GROUPED );
        } )->with( 'product' )->firstOrFail();

        $customer = Customer::first() ?: $this->attemptCreateCustomer();

        $order = new Order;
        $order->code = 'A4TEST-' . uniqid();
        $order->type = 'takeaway';
        $order->payment_status = Order::PAYMENT_PAID;
        $order->customer_id = $customer->id;
        $order->author_id = $this->admin->id;
        $order->subtotal = 6925.00;
        $order->total = 6925.00;
        $order->tendered = 6925.00;
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
            'quantity' => 5,
            'unit_price' => 1385.00,
            'total_price' => 6925.00,
            'status' => 'sold',
            'vat_status' => ThaiVatService::STATUS_STANDARD,
            'vat_rate' => 7,
        ] );

        $order->products()->save( $orderProduct );

        return $order->fresh( 'products' );
    }
}
