<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\TaxGroup;
use App\Models\TransactionHistory;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class GoodsReceiptTest extends TestCase
{
    use DatabaseTransactions,WithAuthentication;

    private Branch $branch;

    private ProductUnitQuantity $row;

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->attemptGetAnyUserFromRole(), 'web');
        $this->branch = Branch::create(['code' => 'T69', 'name' => 'Goods QA', 'is_active' => true, 'author_id' => auth()->id()]);
        $this->provider = Provider::create(['first_name' => 'Goods QA supplier', 'author_id' => auth()->id()]);
        $unit = Unit::firstOrFail();
        $p = Product::factory()->create(['name' => 'Goods QA product', 'stock_management' => 'enabled', 'stock_on_base_unit' => false, 'type' => 'materialized', 'unit_group' => $unit->group_id]);
        $this->row = new ProductUnitQuantity;
        foreach (['product_id' => $p->id, 'unit_id' => $unit->id, 'branch_id' => $this->branch->id, 'quantity' => 10, 'cogs' => 20] as $k => $v) {
            $this->row->$k = $v;
        }$this->row->save();
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'provider_id' => $this->provider->id, 'source' => 'no_bill', 'received_at' => '2026-10-01', 'note' => 'ตรวจนับสินค้าจริง ไม่มีบิลแนบ', 'credit_days' => 7, 'lines' => [['row_id' => $this->row->id, 'quantity' => 3, 'price' => 20]]], $changes);
    }

    public function test_no_bill_receipt_confirms_once_without_fabricated_invoice(): void
    {
        $data = $this->payload();
        $s = app(GoodsReceiptService::class);
        $r = $s->create($data);
        $this->assertEquals($r->id, $s->create($data)->id);
        $this->assertNull($r->purchase_order_id);
        $this->assertNull($r->invoice_date);
        $this->assertEquals(10, $this->row->fresh()->quantity);
        app(PurchaseOrderService::class)->approveBilling($r->id);
        app(PurchaseOrderService::class)->approveBilling($r->id);
        $this->assertEquals(13, $this->row->fresh()->quantity);
        $this->assertEquals('2026-10-08', $r->fresh()->due_date);
        $this->assertEquals(60, $r->fresh()->cost);
        $this->assertNull($r->fresh()->invoice_reference);
        $this->assertEquals(2, TransactionHistory::where('procurement_id', $r->id)->count());
        $this->get('/dashboard/goods-receipts/'.$r->id)->assertOk()->assertSee('ยืนยันเข้าสต๊อกแล้ว')->assertSee('GR-');
        $this->get('/dashboard/goods-receipts?branch_id='.$this->branch->id.'&source=no_bill')->assertOk()->assertSee('Goods QA supplier');
        $this->assertEquals(13, $this->row->fresh()->quantity);
    }

    public function test_invoice_requires_reference_and_date_and_rejects_duplicates(): void
    {
        $this->postJson('/dashboard/goods-receipts', $this->payload(['source' => 'invoice']))->assertUnprocessable();
        $data = $this->payload(['source' => 'invoice', 'invoice_reference' => 'INV-T69', 'invoice_date' => '2026-10-01']);
        $r = app(GoodsReceiptService::class)->create($data);
        $this->assertEquals('INV-T69', $r->invoice_reference);
        $data['request_key'] = (string) Str::uuid();
        $this->postJson('/dashboard/goods-receipts', $data)->assertUnprocessable();
    }

    public function test_no_bill_cannot_claim_tax_and_other_branch_stock_is_rejected(): void
    {
        $tax = TaxGroup::firstOrFail();
        $data = $this->payload();
        $data['lines'][0]['tax_group_id'] = $tax->id;
        $this->postJson('/dashboard/goods-receipts', $data)->assertUnprocessable();
        $other = Branch::create(['code' => 'T69other', 'name' => 'Other', 'is_active' => true, 'author_id' => auth()->id()]);
        $this->postJson('/dashboard/goods-receipts', $this->payload(['branch_id' => $other->id]))->assertNotFound();
        $this->assertEquals(10, $this->row->fresh()->quantity);
    }

    public function test_confirm_requires_ack_and_stale_documents_are_rejected(): void
    {
        $r = app(GoodsReceiptService::class)->create($this->payload());
        $this->postJson('/dashboard/goods-receipts/'.$r->id.'/confirm', [])->assertUnprocessable();
        $this->postJson('/dashboard/goods-receipts/'.$r->id.'/confirm', ['acknowledge' => 1, 'version' => str_repeat('a', 64)])->assertUnprocessable();
        $r->refresh();
        $version = hash('sha256', json_encode($r->getAttributes()));
        $this->post('/dashboard/goods-receipts/'.$r->id.'/confirm', ['acknowledge' => 1, 'version' => $version])->assertRedirect();
        $this->post('/dashboard/goods-receipts/'.$r->id.'/confirm', ['acknowledge' => 1, 'version' => $version])->assertRedirect();
        $this->assertEquals(13, $this->row->fresh()->quantity);
    }

    public function test_cancellation_keeps_history_and_prevents_stocking(): void
    {
        $r = app(GoodsReceiptService::class)->create($this->payload());
        $this->post('/dashboard/goods-receipts/'.$r->id.'/cancel', ['reason' => 'กรอกจำนวนผิด'])->assertRedirect();
        $this->assertEquals('cancelled', $r->fresh()->billing_approval_status);
        $this->get('/dashboard/goods-receipts/'.$r->id)->assertOk()->assertSee('ยกเลิกแล้ว');
        $this->assertEquals(10, $this->row->fresh()->quantity);
        $this->postJson('/dashboard/purchase-trials/'.$r->id, ['action' => 'archive', 'acknowledge' => 1])->assertUnprocessable();
        $this->postJson('/dashboard/goods-receipts/'.$r->id.'/confirm', ['acknowledge' => 1, 'version' => hash('sha256', json_encode($r->fresh()->getAttributes()))])->assertUnprocessable();
    }

    public function test_branch_authorization_applies_to_list_lookup_document_and_confirm(): void
    {
        $r = app(GoodsReceiptService::class)->create($this->payload());
        $staff = $this->attemptGetAnyUserFromRole('nexopos.store.cashier');
        $staff->branch_id = 1;
        $staff->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        Gate::before(fn () => true);
        foreach (['?branch_id='.$this->branch->id, '?lookup=1&q=QA&branch_id='.$this->branch->id, '/'.$r->id] as $path) {
            $this->getJson('/dashboard/goods-receipts'.$path)->assertForbidden();
        }
        $this->postJson('/dashboard/goods-receipts/'.$r->id.'/confirm', ['acknowledge' => 1, 'version' => str_repeat('a', 64)])->assertForbidden();
        $this->getJson('/dashboard/procurements/edit/'.$r->id.'/invoice')->assertForbidden();
    }

    public function test_direct_receipt_cannot_be_deleted_or_modified_through_legacy_api(): void
    {
        $r = app(GoodsReceiptService::class)->create($this->payload());
        $this->expectException(NotAllowedException::class);
        $r->delete();
    }

    public function test_invoice_tax_is_calculated_server_side(): void
    {
        $group = TaxGroup::whereHas('taxes', fn ($q) => $q->where('rate', 7))->firstOrFail();
        $data = $this->payload(['source' => 'invoice', 'invoice_reference' => 'INV-TAX-T69', 'invoice_date' => '2026-10-01', 'lines' => [['row_id' => $this->row->id, 'quantity' => 2, 'price' => 107, 'tax_group_id' => $group->id]]]);
        $r = app(GoodsReceiptService::class)->create($data);
        $this->assertEquals(214, $r->cost);
        $this->assertEquals(14, $r->tax_value);
        app(PurchaseOrderService::class)->approveBilling($r->id);
        $this->assertEquals(12, $this->row->fresh()->quantity);
    }

    public function test_carton_receipt_adds_to_base_stock_in_selected_branch_only(): void
    {
        $group = UnitGroup::create(['name' => 'T69 pack', 'author_id' => auth()->id()]);
        $base = Unit::create(['name' => 'T69 piece', 'identifier' => 't69-piece', 'group_id' => $group->id, 'base_unit' => true, 'value' => 1, 'author_id' => auth()->id()]);
        $pack = Unit::create(['name' => 'T69 carton', 'identifier' => 't69-carton', 'group_id' => $group->id, 'base_unit' => false, 'value' => 12, 'author_id' => auth()->id()]);
        $p = $this->row->product;
        $p->stock_on_base_unit = true;
        $p->unit_group = $group->id;
        $p->save();
        $this->row->unit_id = $base->id;
        $this->row->save();
        $packRow = $this->row->replicate();
        $packRow->unit_id = $pack->id;
        $packRow->quantity = 0;
        $packRow->save();
        session(['selected_branch_id' => 1]);
        $r = app(GoodsReceiptService::class)->create($this->payload(['lines' => [['row_id' => $packRow->id, 'quantity' => 2, 'price' => 120]]]));
        app(PurchaseOrderService::class)->approveBilling($r->id);
        $this->assertEquals(34, $this->row->fresh()->quantity);
        $this->assertEquals(0,$packRow->fresh()->quantity);
        $this->assertEquals(1,session('selected_branch_id'));
    }
}
