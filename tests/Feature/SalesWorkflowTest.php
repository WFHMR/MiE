<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\SalesDelivery;
use App\Models\SalesDocument;
use App\Models\SalesQuotation;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\BusinessDashboardService;
use App\Services\OrdersService;
use App\Services\SalesWorkflowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class SalesWorkflowTest extends TestCase
{
    use DatabaseTransactions,WithAuthentication;

    private $branch;

    private $row;

    private $customer;

    private $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->attemptGetAnyUserFromRole(), 'web');
        $this->branch = Branch::create(['code' => 'T64', 'name' => 'ทดสอบฝ่ายขาย', 'is_active' => true, 'author_id' => auth()->id()]);
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

    public function test_invoice_timing_posts_existing_sale_once_and_dispatch_does_not_deduct_twice(): void
    {
        $q = $this->quote();
        $so = $this->accept($q, 'invoice');
        $this->assertEquals(20, $this->row->fresh()->quantity);
        $o = $this->service->invoice($so->id);
        $this->assertEquals(200, $o->total);
        $this->assertEquals(18, $this->row->fresh()->quantity);
        $this->assertEquals($o->id, $this->service->invoice($so->id)->id);
        $d = SalesDelivery::where('sales_document_id', $so->id)->firstOrFail();
        $data = ['action' => 'dispatch', 'carrier' => 'รถร้าน', 'note' => 'ตรวจครบ', 'acknowledge' => 1];
        $this->service->delivery($d->id, $data);
        $this->service->delivery($d->id, $data);
        $this->assertEquals(18, $this->row->fresh()->quantity);
    }

    public function test_dispatch_timing_keeps_stock_until_dispatch_and_receipt_does_not_move_stock(): void
    {
        $q = $this->quote();
        $so = $this->accept($q, 'dispatch');
        $this->assertEquals(20, $this->row->fresh()->quantity);
        $d = SalesDelivery::where('sales_document_id', $so->id)->firstOrFail();
        $this->service->delivery($d->id, ['action' => 'dispatch', 'carrier' => 'รถร้าน', 'note' => 'ส่งจริง', 'acknowledge' => 1]);
        $this->assertEquals(18, $this->row->fresh()->quantity);
        $this->service->delivery($d->id, ['action' => 'delivered', 'received_by' => 'ผู้รับทดสอบ', 'note' => 'ครบตามรายการ', 'acknowledge' => 1]);
        $this->assertEquals('delivered', $so->fresh()->status);
        $this->assertEquals(18, $this->row->fresh()->quantity);
    }

    public function test_vat_quote_keeps_inclusive_price_tax_and_invoice_consistent(): void
    {
        $g = new TaxGroup;
        $g->name = 'VAT T64';
        $g->author_id = auth()->id();
        $g->save();
        $t = new Tax;
        $t->name = 'VAT7';
        $t->rate = 7;
        $t->tax_group_id = $g->id;
        $t->author_id = auth()->id();
        $t->save();
        $q = $this->service->saveQuote(['request_key' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id, 'name' => 'VAT quote', 'valid_until' => now()->addDays(30)->toDateString(), 'tax_group_id' => $g->id, 'lines' => [['unit_quantity_id' => $this->row->id, 'quantity' => 2, 'unit_price' => 107]]]);
        $this->assertEquals(214, $q->total);
        $this->assertEquals(14, $q->tax);
        $so = $this->accept($q, 'invoice');
        $o = $this->service->invoice($so->id);
        $this->assertEquals(214, $o->total);
        $this->assertEquals(14, $o->tax_value);
    }

    public function test_carton_dispatch_deducts_base_and_inventory_does_not_double_count(): void
    {
        $group = new UnitGroup;
        $group->name = 'T64 carton';
        $group->author_id = auth()->id();
        $group->save();
        $units = [];
        foreach ([1, 10] as $factor) {
            $u = new Unit;
            foreach (['name' => 'T64-'.$factor, 'identifier' => 't64-'.$factor, 'value' => $factor, 'base_unit' => $factor === 1, 'group_id' => $group->id, 'author_id' => auth()->id()] as $k => $value) {
                $u->$k = $value;
            }$u->save();
            $units[] = $u;
        }
        $p = $this->row->product;
        $p->unit_group = $group->id;
        $p->stock_on_base_unit = true;
        $p->save();
        $this->row->unit_id = $units[0]->id;
        $this->row->quantity = 30;
        $this->row->save();
        $base = $this->row;
        $pack = $base->replicate();
        $pack->unit_id = $units[1]->id;
        $pack->quantity = 3;
        $pack->cogs = 300;
        $pack->save();
        $this->row = $pack;
        $so = $this->accept($this->quote(), 'dispatch');
        $d = SalesDelivery::where('sales_document_id', $so->id)->first();
        $this->service->delivery($d->id, ['action' => 'dispatch', 'carrier' => 'รถ', 'note' => 'ส่งลัง', 'acknowledge' => 1]);
        $this->assertEquals(10, $base->fresh()->quantity);
        $data = app(BusinessDashboardService::class)->inventory($this->branch->id);
        $this->assertEquals(1, $data['count']);
        $this->assertEquals(300, $data['value']);
    }

    public function test_voided_linked_bill_restores_stock_and_blocks_dispatch(): void
    {
        $so = $this->accept($this->quote(), 'invoice');
        $order = $this->service->invoice($so->id);
        app(OrdersService::class)->void($order->fresh(), 'ทดสอบยกเลิก');
        $this->assertEquals(20, $this->row->fresh()->quantity);
        $delivery = SalesDelivery::where('sales_document_id', $so->id)->first();
        $this->postJson('/dashboard/sales/deliveries/'.$delivery->id.'/action', ['action' => 'dispatch', 'carrier' => 'รถ', 'note' => 'ส่ง', 'acknowledge' => 1])->assertUnprocessable();
    }

    public function test_kpi_includes_late_open_deliveries_and_current_conversion(): void
    {
        $so = $this->accept($this->quote(), 'dispatch');
        $so->update(['due_at' => now()->toDateString()]);
        $service = app(BusinessDashboardService::class);
        $data = $service->kpi($this->branch->id, now()->format('Y-m'));
        $this->assertEquals(100, $data['values']['conversion']);
        $this->assertEquals(0, $data['values']['on_time']);
        $this->assertEquals([0, 1], $data['delivery_count']);
        $delivery = SalesDelivery::where('sales_document_id', $so->id)->first();
        $this->service->delivery($delivery->id, ['action' => 'dispatch', 'carrier' => 'รถ', 'note' => 'ส่ง', 'acknowledge' => 1]);
        $this->service->delivery($delivery->id, ['action' => 'delivered', 'received_by' => 'ผู้รับ', 'note' => 'รับ', 'acknowledge' => 1]);
        $this->assertEquals(100, $service->kpi($this->branch->id, now()->format('Y-m'))['values']['on_time']);
    }

    public function test_financial_split_tender_change_and_refund_are_counted_once(): void
    {
        $so = $this->accept($this->quote(), 'invoice');
        $order = $this->service->invoice($so->id);
        app(OrdersService::class)->makeOrderSinglePayment(['identifier' => 'bank-payment', 'value' => 50], $order->fresh());
        app(OrdersService::class)->makeOrderSinglePayment(['identifier' => 'cash-payment', 'value' => 200], $order->fresh());
        $dashboard = app(BusinessDashboardService::class);
        $today = now()->toDateString();
        $data = $dashboard->financial($this->branch->id, $today, $today);
        $this->assertEquals(50, $data['channels']['bank-payment']);
        $this->assertEquals(150, $data['channels']['cash-payment']);
        $this->assertEquals(200, $data['recorded_flow']);
        // Historic refund fixture: original sale 200, remaining sale 160, cash refund 40.
        DB::table('nexopos_orders')->where('id', $order->id)->update(['total' => 160, 'payment_status' => Order::PAYMENT_PARTIALLY_REFUNDED]);
        $refund = new OrderRefund;
        $refund->order_id = $order->id;
        $refund->author_id = auth()->id();
        $refund->total = 40;
        $refund->shipping = 0;
        $refund->payment_method = 'cash-payment';
        $refund->save();
        $data = $dashboard->financial($this->branch->id, $today, $today);
        $this->assertEquals(200, $data['collections']);
        $this->assertEquals(40, $data['refunds']);
        $this->assertEquals(160, $data['recorded_flow']);
        $this->assertEquals(0, $data['due']);
    }

    public function test_all_separate_pages_render_and_quotes_do_not_count_as_sales(): void
    {
        $q = $this->quote();
        $base = '/dashboard/';
        foreach (['sales/quotations', 'sales/quotations/create', 'sales/quotations/'.$q->id, 'sales/quotations/'.$q->id.'/edit', 'sales/orders', 'sales/deliveries', 'business-dashboards/sales', 'business-dashboards/purchase', 'business-dashboards/inventory', 'business-dashboards/financial', 'business-dashboards/kpi'] as $url) {
            $this->get($base.$url.'?branch_id='.$this->branch->id)->assertOk();
        }
        $so = $this->accept($q, 'dispatch');
        $d = SalesDelivery::where('sales_document_id', $so->id)->first();
        $this->get($base.'sales/orders/'.$so->id)->assertOk();
        $this->get($base.'sales/deliveries/'.$d->id)->assertOk();
        $this->assertEquals(0, app(BusinessDashboardService::class)->sales($this->branch->id, now()->toDateString(), now()->toDateString())['total']);
    }

    public function test_payment_after_invoice_does_not_deduct_stock_again_and_dashboards_use_same_bill(): void
    {
        $so = $this->accept($this->quote(), 'invoice');
        $order = $this->service->invoice($so->id);
        app(OrdersService::class)->makeOrderSinglePayment(['identifier' => 'cash-payment', 'value' => 250], $order->fresh());
        $this->assertEquals(18, $this->row->fresh()->quantity);
        $this->assertEquals('paid', $order->fresh()->payment_status);
        $dash = app(BusinessDashboardService::class);
        $data = $dash->sales($this->branch->id, now()->toDateString(), now()->toDateString());
        $this->assertEquals(200, $data['total']);
        $this->assertEquals(60, $data['cost']);
        $this->assertEquals(140, $data['profit']);
        $finance = $dash->financial($this->branch->id, now()->toDateString(), now()->toDateString());
        $this->assertEquals(200, $finance['collections']);
        $this->assertEquals(0, $finance['due']);
    }

    public function test_insufficient_stock_rolls_back_invoice_and_delivery(): void
    {
        $so = $this->accept($this->quote(), 'dispatch');
        $d = SalesDelivery::where('sales_document_id', $so->id)->first();
        $this->row->quantity = 1;
        $this->row->save();
        $count = Order::count();
        try {
            $this->service->delivery($d->id, ['action' => 'dispatch', 'carrier' => 'รถร้าน', 'note' => 'ส่ง', 'acknowledge' => 1]);
            $this->fail('Must reject shortage');
        } catch (\Throwable $e) {
            if ($e instanceof AssertionFailedError) {
                throw $e;
            }
        }
        $this->assertNull($so->fresh()->order_id);
        $this->assertEquals($count, Order::count());
        $this->assertEquals(1, $this->row->fresh()->quantity);
        $this->assertEquals('preparing', $d->fresh()->status);
    }

    public function test_cross_branch_access_and_product_selection_are_denied(): void
    {
        $q = $this->quote();
        $other = Branch::create(['code' => 'T64B', 'name' => 'อีกสาขา', 'is_active' => true, 'author_id' => auth()->id()]);
        $this->getJson('/dashboard/sales/lookup?branch_id='.$other->id.'&type=product&search=T64')->assertOk()->assertExactJson([]);
        $staff = $this->attemptGetAnyUserFromRole('nexopos.store.cashier');
        $staff->branch_id = $other->id;
        $staff->save();
        $this->app['auth']->forgetGuards();
        session()->forget('password_hash_web');
        $this->actingAs($staff, 'web');
        Gate::before(fn () => true);
        foreach (['sales/quotations/'.$q->id, 'sales/quotations?branch_id='.$this->branch->id, 'business-dashboards/sales?branch_id='.$this->branch->id, 'business-dashboards/kpi?branch_id='.$this->branch->id] as $url) {
            $this->getJson('/dashboard/'.$url)->assertForbidden();
        }
        $this->postJson('/dashboard/business-dashboards/kpi/targets', ['branch_id' => $other->id, 'month' => '2026-10', 'targets' => ['sales' => 100]])->assertForbidden();
    }

    public function test_monthly_targets_are_isolated_and_zero_target_is_preserved(): void
    {
        $url = '/dashboard/business-dashboards/kpi/targets';
        $payload = ['branch_id' => $this->branch->id, 'month' => '2026-10', 'targets' => ['sales' => 1000, 'stockout' => 0]];
        $this->post($url, $payload)->assertRedirect();
        $service = app(BusinessDashboardService::class);
        $data = $service->kpi($this->branch->id, '2026-10');
        $this->assertEquals(0, $data['targets']['stockout']);
        $this->assertEquals(1000, $data['targets']['sales']);
        $this->assertNull($data['values']['conversion']);
        $this->assertCount(0, $service->kpi($this->branch->id, '2026-11')['targets']);
        $payload['targets']['stockout'] = 101;
        $this->postJson($url, $payload)->assertUnprocessable();
    }

    public function test_expired_quote_and_changed_document_cannot_be_accepted_or_dispatched(): void
    {
        $q = $this->quote();
        $q->update(['valid_until' => '2020-01-01']);
        $this->postJson('/dashboard/sales/quotations/'.$q->id.'/action', ['action' => 'issue', 'version' => 1, 'acknowledge' => 1])->assertUnprocessable();
        $so = $this->accept($this->quote(), 'invoice');
        $order = $this->service->invoice($so->id);
        $line = $order->products()->first();
        $line->quantity = 1;
        $line->save();
        $d = SalesDelivery::where('sales_document_id', $so->id)->first();
        $this->postJson('/dashboard/sales/deliveries/'.$d->id.'/action', ['action' => 'dispatch', 'carrier' => 'รถร้าน', 'note' => 'ส่ง', 'acknowledge' => 1])->assertUnprocessable();
        $this->assertEquals('preparing', $d->fresh()->status);
    }
}
