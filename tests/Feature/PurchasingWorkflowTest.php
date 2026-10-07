<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Procurement;
use App\Models\ProcurementProduct;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\PurchaseRequest;
use App\Models\PurchaseReturn;
use App\Models\TaxGroup;
use App\Models\TransactionAccount;
use App\Models\TransactionHistory;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\PaymentVoucherService;
use App\Services\ProviderService;
use App\Services\PurchaseOrderService;
use App\Services\PurchasingWorkflowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class PurchasingWorkflowTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private Branch $branch;

    private Branch $other;

    private Provider $provider;

    private ProductUnitQuantity $row;

    private Procurement $receipt;

    private ProcurementProduct $line;

    private PurchasingWorkflowService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->attemptGetAnyUserFromRole(), 'web');
        $this->branch = Branch::create(['code' => 'WF-T62', 'name' => 'สาขาจัดซื้อทดสอบ', 'is_active' => true, 'author_id' => auth()->id()]);
        $this->other = Branch::create(['code' => 'WF-OTHER', 'name' => 'สาขาใหม่อื่น', 'is_active' => true, 'author_id' => auth()->id()]);
        session(['selected_branch_id' => $this->branch->id]);
        $this->provider = Provider::create(['first_name' => 'ผู้ขาย workflow', 'author_id' => auth()->id()]);
        $unit = Unit::firstOrFail();
        $p = Product::factory()->create(['name' => 'สินค้าจัดซื้อ workflow', 'provider_id' => $this->provider->id, 'stock_management' => 'enabled', 'stock_on_base_unit' => false, 'type' => 'materialized', 'unit_group' => $unit->group_id]);
        $this->row = new ProductUnitQuantity;
        foreach (['product_id' => $p->id, 'unit_id' => $unit->id, 'branch_id' => $this->branch->id, 'quantity' => 10, 'cogs' => 20] as $k => $v) {
            $this->row->$k = $v;
        }
        $this->row->save();
        $this->receipt = Procurement::withoutEvents(function () {
            $r = new Procurement;
            foreach (['branch_id' => $this->branch->id, 'provider_id' => $this->provider->id, 'author_id' => auth()->id(), 'name' => 'RECEIPT-T62', 'delivery_status' => 'stocked', 'payment_status' => 'unpaid', 'cost' => 200, 'tax_value' => 0, 'delivery_time' => now(), 'invoice_date' => now()->toDateString()] as $k => $v) {
                $r->$k = $v;
            } $r->save();

            return $r;
        });
        $this->line = ProcurementProduct::withoutEvents(function () {
            $p = new ProcurementProduct;
            foreach (['procurement_id' => $this->receipt->id, 'product_id' => $this->row->product_id, 'unit_id' => $this->row->unit_id, 'name' => 'สินค้าจัดซื้อ workflow', 'quantity' => 10, 'available_quantity' => 10, 'tax_type' => 'inclusive', 'purchase_price' => 20, 'total_purchase_price' => 200, 'author_id' => auth()->id()] as $k => $v) {
                $p->$k = $v;
            } $p->save();

            return $p;
        });
        $this->service = app(PurchasingWorkflowService::class);
    }

    private function requestDocument(): PurchaseRequest
    {
        return $this->service->request(['request_key' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'name' => 'ขอซื้อทดสอบ', 'reason' => 'เติมสินค้า', 'department' => 'หน้าร้าน', 'lines' => [['product_id' => $this->row->product_id, 'unit_id' => $this->row->unit_id, 'quantity' => 3]]]);
    }

    private function quoteData(array $changes = []): array
    {
        return array_merge(['provider_id' => $this->provider->id, 'reference' => 'Q-001', 'valid_until' => now()->addDays(7)->toDateString(), 'credit_days' => 30, 'tax_type' => 'inclusive', 'prices' => [20]], $changes);
    }

    private function returnDocument(string $mode = 'credit', int $qty = 2): PurchaseReturn
    {
        return $this->service->createReturn(['request_key' => (string) Str::uuid(), 'procurement_id' => $this->receipt->id, 'mode' => $mode, 'reason' => 'ชำรุดจากผู้ขาย', 'quantities' => [$this->line->id => $qty]]);
    }

    private function settle(PurchaseReturn $r): PurchaseReturn
    {
        return $this->service->settleReturn($r->id, ['reference' => 'CN-T62', 'note' => 'ตรวจหลักฐานครบแล้ว', 'acknowledge' => 1]);
    }

    public function test_request_approval_quotes_and_one_po_without_stock_change(): void
    {
        $r = $this->requestDocument();
        $this->get('/dashboard/purchasing/requests/'.$r->id)->assertOk()->assertSee('รออนุมัติ');
        $this->postJson('/dashboard/purchasing/requests/'.$r->id.'/quotes', $this->quoteData())->assertUnprocessable();
        $this->service->decide($r->id, ['version' => 1, 'action' => 'approved', 'decision_note' => 'อนุมัติ']);
        $q = $this->service->quote($r->id, $this->quoteData());
        $otherProvider = Provider::create(['first_name' => 'ผู้ขายรายที่สอง', 'author_id' => auth()->id()]);
        $second = $this->service->quote($r->id, $this->quoteData(['provider_id' => $otherProvider->id, 'reference' => 'Q-002', 'prices' => [18]]));
        $this->get('/dashboard/purchasing/requests/'.$r->id)->assertOk()->assertSee('60.00')->assertSee('54.00');
        $po = $this->service->selectQuote($r->id, $second->id);
        $again = $this->service->selectQuote($r->id, $second->id);
        $this->assertEquals($po->id, $again->id);
        $this->assertEquals($otherProvider->id, $po->provider_id);
        $this->assertEquals(54, $po->lines[0]['total_purchase_price']);
        $this->assertEquals('ordered', $r->fresh()->status);
        $this->assertEquals(10, $this->row->fresh()->quantity);
        $this->get('/dashboard/purchase-orders/'.$po->id)->assertOk();
        $this->getJson('/dashboard/purchase-orders/'.$po->id.'/edit')->assertUnprocessable();
    }

    public function test_expired_quote_and_changed_approval_cannot_create_po(): void
    {
        $r = $this->requestDocument();
        $this->service->decide($r->id, ['version' => 1, 'action' => 'approved', 'decision_note' => 'อนุมัติ']);
        $q = $this->service->quote($r->id, $this->quoteData(['valid_until' => now()->subDay()->toDateString()]));
        $this->postJson('/dashboard/purchasing/requests/'.$r->id.'/select', ['quote_id' => $q->id, 'acknowledge' => 1])->assertUnprocessable();
        $this->postJson('/dashboard/purchasing/requests/'.$r->id.'/decision', ['version' => 1, 'action' => 'rejected', 'decision_note' => 'เก่า'])->assertUnprocessable();
        $this->assertNull($r->fresh()->purchase_order_id);
    }

    public function test_quote_tax_is_computed_server_side(): void
    {
        $r = $this->requestDocument();
        $this->service->decide($r->id, ['version' => 1, 'action' => 'approved', 'decision_note' => 'อนุมัติ']);
        $group = TaxGroup::whereHas('taxes', fn ($q) => $q->where('rate', 7))->firstOrFail();
        $q = $this->service->quote($r->id, $this->quoteData(['tax_type' => 'exclusive', 'tax_group_id' => $group->id, 'prices' => [100], 'total' => 1]));
        $this->assertEquals(321, $q->total);
        $this->assertEquals(21, $q->lines[0]['tax_value']);
    }

    public function test_credit_return_cuts_stock_once_and_reduces_actual_payment(): void
    {
        $r = $this->returnDocument();
        $this->assertEquals(10, $this->row->fresh()->quantity);
        $this->service->confirmReturn($r->id);
        $this->service->confirmReturn($r->id);
        $this->assertEquals(8, $this->row->fresh()->quantity);
        $this->assertEquals(200, $this->receipt->fresh()->payable_total);
        $this->settle($r);
        $this->settle($r);
        $this->assertEquals(160, $this->receipt->fresh()->payable_total);
        $this->assertEquals(200, $this->receipt->fresh()->cost);
        $this->assertEquals(40, $this->receipt->fresh()->purchase_return_credit);
        $this->assertEquals(160, app(ProviderService::class)->receiptSummary($this->provider->id, $this->branch->id)['amount_due']);
        $v = app(PaymentVoucherService::class)->create(['request_key' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'provider_id' => $this->provider->id, 'mode' => 'payment', 'paid_at' => now()->toDateString(), 'method' => 'cash', 'receipts' => [$this->receipt->id]]);
        $this->assertEquals(160, $v->total);
        app(PaymentVoucherService::class)->confirm($v->id);
        $this->assertEquals('paid', $this->receipt->fresh()->payment_status);
        $this->assertEquals(2, TransactionHistory::where('name', 'คืนซัพพลายเออร์ RT-'.$r->id)->count());
    }

    public function test_refund_has_accounting_but_does_not_change_paid_invoice(): void
    {
        DB::table('nexopos_procurements')->where('id', $this->receipt->id)->update(['payment_status' => 'paid']);
        $r = $this->returnDocument('refund');
        $this->service->confirmReturn($r->id);
        $this->settle($r);
        $this->assertEquals(8, $this->row->fresh()->quantity);
        $this->assertEquals('paid', $this->receipt->fresh()->payment_status);
        $this->assertEquals(0, $this->receipt->fresh()->purchase_return_credit);
        $this->assertEquals(2, TransactionHistory::where('name', 'คืนซัพพลายเออร์ RT-'.$r->id)->count());
    }

    public function test_exchange_restores_stock_once_and_does_not_reduce_debt(): void
    {
        $r = $this->returnDocument('exchange');
        $this->service->confirmReturn($r->id);
        $this->assertEquals(8, $this->row->fresh()->quantity);
        $this->settle($r);
        $this->settle($r);
        $this->assertEquals(10, $this->row->fresh()->quantity);
        $this->assertEquals(200, $this->receipt->fresh()->payable_total);
        $this->assertEquals(2, ProductHistory::where('procurement_id', $this->receipt->id)->count());
    }

    public function test_over_return_and_insufficient_stock_are_atomic(): void
    {
        $a = $this->returnDocument('credit', 8);
        $b = $this->returnDocument('credit', 3);
        $this->service->confirmReturn($a->id);
        $this->postJson('/dashboard/purchasing/returns/'.$b->id, ['action' => 'confirm', 'acknowledge' => 1])->assertUnprocessable();
        $this->assertEquals('draft', $b->fresh()->status);
        $this->assertEquals(2, $this->row->fresh()->quantity);
        $c = $this->returnDocument('credit', 2);
        $this->row->quantity = 1;
        $this->row->save();
        $this->postJson('/dashboard/purchasing/returns/'.$c->id, ['action' => 'confirm', 'acknowledge' => 1])->assertUnprocessable();
        $this->assertEquals(1, $this->row->fresh()->quantity);
    }

    public function test_missing_accounting_does_not_partially_settle_return(): void
    {
        $r = $this->returnDocument();
        $this->service->confirmReturn($r->id);
        DB::table('nexopos_transactions_actions_rules')->where('on', 'procurement_unpaid')->delete();
        $this->postJson('/dashboard/purchasing/returns/'.$r->id, ['action' => 'settle', 'reference' => 'CN', 'note' => 'test', 'acknowledge' => 1])->assertUnprocessable();
        $this->assertEquals('confirmed', $r->fresh()->status);
        $this->assertEquals(0, $this->receipt->fresh()->purchase_return_credit);
    }

    public function test_pending_credit_blocks_payment_and_cancel_releases_it(): void
    {
        $r = $this->returnDocument();
        try {
            $this->service->assertPaymentReady($this->receipt);
            $this->fail('Must block');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ใบคืน', $e->getMessage());
        }
        $this->service->cancelReturn($r->id);
        $this->service->assertPaymentReady($this->receipt);
        $this->assertEquals('cancelled', $r->fresh()->status);
        $this->assertEquals(10, $this->row->fresh()->quantity);
    }

    public function test_cross_branch_access_denied_even_with_permission(): void
    {
        $r = $this->requestDocument();
        $ret = $this->returnDocument();
        $staff = $this->attemptGetAnyUserFromRole('nexopos.store.cashier');
        $staff->branch_id = $this->other->id;
        $staff->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        Gate::before(fn () => true);
        foreach (['requests/'.$r->id, 'returns/'.$ret->id, 'report?branch_id='.$this->branch->id, 'products?branch_id='.$this->branch->id.'&search=test'] as $path) {
            $this->getJson('/dashboard/purchasing/'.$path)->assertForbidden();
        }
        $this->postJson('/dashboard/purchasing/returns/'.$ret->id, ['action' => 'confirm', 'acknowledge' => 1])->assertForbidden();
        $this->assertEquals(10, $this->row->fresh()->quantity);
    }

    public function test_pages_report_and_export_only_include_selected_branch(): void
    {
        $other = $this->receipt->replicate();
        $other->branch_id = $this->other->id;
        $other->name = 'SECRET-OTHER-BRANCH';
        Procurement::withoutEvents(fn () => $other->save());
        $r = $this->requestDocument();
        $ret = $this->returnDocument();
        foreach (['requests?branch_id='.$this->branch->id, 'requests/'.$r->id, 'returns?branch_id='.$this->branch->id.'&receipt_id='.$this->receipt->id, 'returns/'.$ret->id, 'report?branch_id='.$this->branch->id] as $url) {
            $this->get('/dashboard/purchasing/'.$url)->assertOk()->assertDontSee('SECRET-OTHER-BRANCH');
        }
        $csv = $this->get('/dashboard/purchasing/report?branch_id='.$this->branch->id.'&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('RECEIPT-T62', $csv);
        $this->assertStringNotContainsString('SECRET-OTHER-BRANCH', $csv);
    }

    public function test_selected_quote_can_be_received_and_approved_through_existing_workflow(): void
    {
        $r = $this->requestDocument();
        $this->service->decide($r->id, ['version' => 1, 'action' => 'approved', 'decision_note' => 'อนุมัติ']);
        $q = $this->service->quote($r->id, $this->quoteData());
        $po = $this->service->selectQuote($r->id, $q->id);
        $receipt = app(PurchaseOrderService::class)->receive($po->id, ['version' => 1, 'quantities' => [3], 'acknowledge' => 1, 'received_at' => now()->toDateString(), 'invoice_date' => now()->toDateString()]);
        $this->assertEquals(10, $this->row->fresh()->quantity);
        app(PurchaseOrderService::class)->approveBilling($receipt->id);
        $this->assertEquals(13, $this->row->fresh()->quantity);
        $this->assertEquals(60, $receipt->fresh()->cost);
    }

    public function test_new_branch_uses_same_request_and_return_rules_without_affecting_other_branch(): void
    {
        $original = $this->row;
        $this->row = $this->row->replicate();
        $this->row->branch_id = $this->other->id;
        $this->row->quantity = 15;
        $this->row->save();
        $this->branch = $this->other;
        session(['selected_branch_id' => $this->other->id]);
        DB::table('nexopos_procurements')->where('id', $this->receipt->id)->update(['branch_id' => $this->other->id]);
        $r = $this->requestDocument();
        $this->assertEquals($this->other->id, $r->branch_id);
        $ret = $this->returnDocument('exchange');
        $this->service->confirmReturn($ret->id);
        $this->assertEquals(13, $this->row->fresh()->quantity);
        $this->assertEquals(10, $original->fresh()->quantity);
        $this->settle($ret);
        $this->assertEquals(15, $this->row->fresh()->quantity);
    }

    public function test_no_permission_cannot_approve_or_return(): void
    {
        $r = $this->requestDocument();
        $ret = $this->returnDocument();
        $staff = $this->attemptGetAnyUserFromRole('nexopos.store.cashier');
        $staff->branch_id = $this->branch->id;
        $staff->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        Gate::before(fn () => false);
        $this->postJson('/dashboard/purchasing/requests/'.$r->id.'/decision', ['version' => 1, 'action' => 'approved', 'decision_note' => 'test'])->assertForbidden();
        $this->postJson('/dashboard/purchasing/returns/'.$ret->id, ['action' => 'confirm', 'acknowledge' => 1])->assertForbidden();
        $this->assertEquals('submitted', $r->fresh()->status);
    }

    public function test_full_credit_zero_due_and_stale_voucher_cannot_pay_original_amount(): void
    {
        $v = app(PaymentVoucherService::class)->create(['request_key' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'provider_id' => $this->provider->id, 'mode' => 'payment', 'paid_at' => now()->toDateString(), 'method' => 'cash', 'receipts' => [$this->receipt->id]]);
        $r = $this->returnDocument('credit', 10);
        $this->service->confirmReturn($r->id);
        $this->settle($r);
        $this->assertEquals(0, $this->receipt->fresh()->payable_total);
        try {
            app(PaymentVoucherService::class)->confirm($v->id);
            $this->fail('Stale voucher should not pay');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('เปลี่ยน', $e->getMessage());
        }
        $this->assertEquals('draft', $v->fresh()->status);
    }

    public function test_refund_can_compose_balanced_debt_and_payment_rules(): void
    {
        DB::table('nexopos_procurements')->where('id', $this->receipt->id)->update(['payment_status' => 'paid']);
        DB::table('nexopos_transactions_actions_rules')->where('on', 'procurement_paid')->delete();
        $r = $this->returnDocument('refund');
        $this->service->confirmReturn($r->id);
        $this->settle($r);
        $entries = TransactionHistory::where('name', 'คืนซัพพลายเออร์ RT-'.$r->id)->get();
        $this->assertCount(2, $entries);
        $this->assertEquals($entries->where('operation', 'debit')->sum('value'), $entries->where('operation', 'credit')->sum('value'));
        $this->assertEquals('settled', $r->fresh()->status);
    }

    public function test_billing_history_displays_approved_receipts_without_changing_stock(): void
    {
        DB::table('nexopos_procurements')->where('id', $this->receipt->id)->update(['purchase_order_id' => 123, 'billing_approval_status' => 'approved']);
        $base = '/dashboard/billing-approval?branch_id='.$this->branch->id;
        $this->get($base)->assertOk()->assertDontSee('RECEIPT-T62');
        $this->get($base.'&status=all&purchase_order_id=123')->assertOk()->assertSee('RECEIPT-T62')->assertSee('billing-review text-fontcolor')->assertDontSee('>ตรวจเอกสารและยืนยันวางบิล<', false);
        $this->get($base.'&status=approved')->assertOk()->assertSee('RECEIPT-T62');
        $this->get($base.'&status=all&purchase_order_id=124')->assertOk()->assertDontSee('RECEIPT-T62');
        $this->get('/dashboard/billing-approval?branch_id='.$this->other->id.'&status=all')->assertOk()->assertDontSee('RECEIPT-T62');
        $this->getJson($base.'&status=invalid')->assertUnprocessable();
        $this->assertEquals(10, $this->row->fresh()->quantity);
        $this->assertEquals('approved', $this->receipt->fresh()->billing_approval_status);
    }

    public function test_explicit_refund_accounts_are_balanced_and_duplicate_account_is_rejected(): void
    {
        DB::table('nexopos_procurements')->where('id', $this->receipt->id)->update(['payment_status' => 'paid']);
        DB::table('nexopos_transactions_actions_rules')->delete();
        $accounts = TransactionAccount::take(2)->pluck('id');
        $r = $this->returnDocument('refund');
        $this->service->confirmReturn($r->id);
        $this->get('/dashboard/purchasing/returns/'.$r->id)->assertOk()->assertSee('บัญชีเดบิต');
        $this->postJson('/dashboard/purchase-trials/'.$this->receipt->id, ['action' => 'archive', 'acknowledge' => 1])->assertStatus(422);
        $data = ['reference' => 'REFUND-T62', 'note' => 'รับเงินตามหลักฐานครบ', 'acknowledge' => 1, 'debit_account_id' => $accounts[0], 'credit_account_id' => $accounts[0]];
        try {
            $this->service->settleReturn($r->id, $data);
            $this->fail('Same account must fail');
        } catch (ValidationException $e) {
            $this->assertEquals('confirmed', $r->fresh()->status);
        }
        $data['credit_account_id'] = $accounts[1];
        $this->service->settleReturn($r->id, $data);
        $this->service->settleReturn($r->id, $data);
        $entries = TransactionHistory::where('name', 'คืนซัพพลายเออร์ RT-'.$r->id)->get();
        $this->assertCount(2, $entries);
        $this->assertEquals(40, $entries->where('transaction_account_id', $accounts[0])->where('operation', 'debit')->sum('value'));
        $this->assertEquals(40, $entries->where('transaction_account_id', $accounts[1])->where('operation', 'credit')->sum('value'));
        $this->assertEquals(200, $this->receipt->fresh()->cost);
    }

    public function test_carton_return_uses_branch_base_stock_and_restores_same_amount(): void
    {
        $group = new UnitGroup;
        $group->name = 'T62 carton group';
        $group->author_id = auth()->id();
        $group->save();
        $base = new Unit;
        foreach (['name' => 'ชิ้น', 'identifier' => 't62-unit', 'value' => 1, 'base_unit' => true, 'group_id' => $group->id, 'author_id' => auth()->id()] as $k => $v) {
            $base->$k = $v;
        } $base->save();
        $pack = new Unit;
        foreach (['name' => 'ลัง', 'identifier' => 't62-pack', 'value' => 10, 'base_unit' => false, 'group_id' => $group->id, 'author_id' => auth()->id()] as $k => $v) {
            $pack->$k = $v;
        } $pack->save();
        $p = $this->row->product;
        $p->unit_group = $group->id;
        $p->stock_on_base_unit = true;
        $p->save();
        $this->row->unit_id = $base->id;
        $this->row->quantity = 100;
        $this->row->save();
        DB::table('nexopos_procurements_products')->where('id', $this->line->id)->update(['unit_id' => $pack->id]);
        $r = $this->returnDocument('exchange', 2);
        $this->service->confirmReturn($r->id);
        $this->assertEquals(80, $this->row->fresh()->quantity);
        $this->settle($r);
        $this->assertEquals(100, $this->row->fresh()->quantity);
    }
}
