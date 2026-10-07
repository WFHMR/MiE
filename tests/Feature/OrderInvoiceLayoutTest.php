<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\SalesDocument;
use App\Models\SalesQuotation;
use App\Models\TaxInvoice;
use App\Models\Unit;
use App\Services\SalesWorkflowService;
use App\Services\ThaiTaxInvoiceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class OrderInvoiceLayoutTest extends TestCase
{
    public function test_temporary_delivery_has_own_title_and_keeps_order_unchanged(): void
    {
        $order = $this->sale();
        $before = $order->fresh()->getAttributes();
        $this->get('/dashboard/orders/invoice/'.$order->id.'?print=1&document=temporary')->assertOk()
            ->assertSee('ใบส่งสินค้าชั่วคราว / ใบฝากสินค้าชั่วคราว')->assertSee('TEMPORARY DELIVERY ORDER')
            ->assertSee($order->code)->assertSee('ผู้อนุมัติจ่ายสินค้า')->assertSee('รายการสินค้า');
        $this->get('/dashboard/orders/temporary-delivery?code='.$order->code)->assertOk()->assertSee($order->code)->assertSee('เปิด / พิมพ์ A4');
        $this->assertEquals($before, $order->fresh()->getAttributes());
        $this->assertEquals(18, $this->row->fresh()->quantity);
    }

    use DatabaseTransactions,WithAuthentication;

    private $branch;

    private $row;

    private $customer;

    private $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->attemptGetAnyUserFromRole(), 'web');
        $this->branch = Branch::create(['code' => 'T72', 'name' => 'ทดสอบฝ่ายขาย', 'is_active' => true, 'author_id' => auth()->id()]);
        session(['selected_branch_id' => $this->branch->id]);
        $this->customer = Customer::firstOrFail();
        $this->customer->credit_limit_amount = 0;
        $this->customer->save();
        ns()->option->set('ns_orders_allow_incomplete', true);
        ns()->option->set('ns_pos_vat', 'disabled');
        $unit = Unit::firstOrFail();
        $p = Product::factory()->create(['name' => 'สินค้า T64', 'stock_management' => 'enabled', 'stock_on_base_unit' => false, 'type' => 'materialized', 'unit_group' => $unit->group_id, 'tax_type' => 'disabled', 'tax_group_id' => null]);
        $this->row = new ProductUnitQuantity;
        foreach (['product_id' => $p->id, 'unit_id' => $unit->id, 'branch_id' => $this->branch->id, 'quantity' => 20, 'sale_price' => 100, 'cogs' => 30] as $k => $v) {
            $this->row->$k = $v;
        }$this->row->save();
        $this->service = app(SalesWorkflowService::class);
    }

    private function quote(): SalesQuotation
    {
        return $this->service->saveQuote(['request_key' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id, 'name' => 'ใบเสนอราคา T64', 'valid_until' => now()->addDays(30)->toDateString(), 'lines' => [['unit_quantity_id' => $this->row->id, 'quantity' => 2, 'unit_price' => 100]]]);
    }

    private function accept($q, $timing): SalesDocument
    {
        $this->service->quoteAction($q->id, ['action' => 'issue', 'version' => 1, 'acknowledge' => 1]);

        return $this->service->accept($q->id, ['stock_timing' => $timing, 'due_at' => now()->addDays(3)->toDateString(), 'shipping_address' => 'ที่อยู่ทดสอบ', 'acceptance_note' => 'ลูกค้ายืนยันตามเอกสาร', 'acknowledge' => 1]);
    }

    private function sale(): Order
    {
        return $this->service->invoice($this->accept($this->quote(), 'invoice')->id);
    }

    public function test_print_uses_order_branch_not_selected_branch_and_preserves_amounts(): void
    {
        $this->branch->update(['address' => 'ที่อยู่สาขาออกบิล T72', 'phone' => '039123456']);
        $order = $this->sale();
        $before = $order->fresh()->getAttributes();
        $other = Branch::create(['code' => 'T72OTHER', 'name' => 'สาขาอื่นที่ห้ามพิมพ์', 'is_active' => true, 'author_id' => auth()->id()]);
        session(['selected_branch_id' => $other->id]);
        $this->get('/dashboard/orders/invoice/'.$order->id.'?print=1')->assertOk()->assertSee('ที่อยู่สาขาออกบิล T72')->assertDontSee('สาขาอื่นที่ห้ามพิมพ์')->assertSee('ใบส่งสินค้า / ใบแจ้งหนี้')->assertSee('ผู้รับสินค้า')->assertSee('ผู้ส่งสินค้า')->assertSee('200.00')->assertDontSee('N/A')->assertDontSee('ns-order-invoice');
        $this->assertEquals($before, $order->fresh()->getAttributes());
        $this->assertEquals(18, $this->row->fresh()->quantity);
    }

    public function test_paid_and_long_multiline_documents_render_without_fake_page_count(): void
    {
        $order = $this->sale();
        Order::withoutEvents(function () use ($order) {
            $order->payment_status = 'paid';
            $order->tendered = 200;
            $order->save();
        });
        $line = $order->products()->first();
        for ($i = 0; $i < 45; $i++) {
            $copy = $line->replicate();
            $copy->name = 'ชื่อสินค้ายาว '.str_repeat('รายละเอียดสินค้า ', 15);
            $copy->save();
        }
        $this->get('/dashboard/orders/invoice/'.$order->id.'?print=1')->assertOk()->assertSee('ใบส่งสินค้า / ใบเสร็จรับเงิน')->assertSee('ผู้รับเงิน')->assertSee('table-header-group')->assertDontSee('1/1');
    }

    public function test_existing_full_tax_invoice_keeps_snapshot_and_number(): void
    {
        ns()->option->set('ns_thai_vat_registered', 'yes');
        ns()->option->set('ns_thai_tax_id', '0223554000076');
        ns()->option->set('ns_thai_tax_registered_name', 'ร้านในเอกสารเดิม T72');
        ns()->option->set('ns_thai_tax_registered_address', 'ที่อยู่เดิม');
        ns()->option->set('ns_thai_tax_branch_code', '00000');
        $order = $this->sale();
        Order::withoutEvents(function () use ($order) {
            $order->payment_status = 'paid';
            $order->tendered = 200;
            $order->save();
        });
        $invoice = app(ThaiTaxInvoiceService::class)->issue(order: $order, type: TaxInvoice::TYPE_FULL, buyer: ['name' => 'ผู้ซื้อเดิม T72', 'address' => 'ที่อยู่ผู้ซื้อ', 'tax_id' => '3430300893753'], authorId: auth()->id());
        ns()->option->set('ns_thai_tax_registered_name', 'ร้านชื่อใหม่ห้ามแทนที่');
        $this->get('/dashboard/orders/invoice/'.$order->id.'?print=1')->assertOk()->assertSee($invoice->number)->assertSee('ร้านในเอกสารเดิม T72')->assertSee('ผู้ซื้อเดิม T72')->assertDontSee('ร้านชื่อใหม่ห้ามแทนที่')->assertSee('DELIVERY/INVOICE/RECEIPT')->assertDontSee('1/1');
        $this->assertEquals(1, TaxInvoice::where('order_id', $order->id)->count());
    }

    public function test_staff_cannot_print_other_branch_invoice(): void
    {
        $order = $this->sale();
        $staff = $this->attemptGetAnyUserFromRole('nexopos.store.cashier');
        $staff->branch_id = 1;
        $staff->save();
        $this->app['auth']->forgetGuards();
        session()->forget('password_hash_web');
        $this->actingAs($staff, 'web');
        Gate::before(fn () => true);
        $this->getJson('/dashboard/orders/invoice/'.$order->id.'?print=1')->assertForbidden();
    }
}
