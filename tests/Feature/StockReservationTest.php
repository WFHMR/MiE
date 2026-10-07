<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\SalesDelivery;
use App\Models\SalesDocument;
use App\Models\SalesQuotation;
use App\Models\StockCount;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\OrdersService;
use App\Services\ProductService;
use App\Services\SalesWorkflowService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class StockReservationTest extends TestCase
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
        $this->branch = Branch::create(['code' => 'T70', 'name' => 'ทดสอบฝ่ายขาย', 'is_active' => true, 'author_id' => auth()->id()]);
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

    private function holds(): StockReservationService
    {
        return app(StockReservationService::class);
    }

    public function test_reserve_release_and_replay_preserve_physical_stock(): void
    {
        $so = $this->accept($this->quote(), 'invoice');
        $this->holds()->reserve($so->id);
        $this->holds()->reserve($so->id);
        $this->assertEquals(20, $this->row->fresh()->quantity);
        $this->assertEquals(2, $this->row->fresh()->reserved_quantity);
        $this->get('/dashboard/sales/reservations?branch_id='.$this->branch->id)->assertOk()->assertSee('SO-'.$so->id);
        $this->get('/dashboard/sales/orders/'.$so->id)->assertOk()->assertSee('จองอยู่');
        $this->holds()->release($so->id);
        $this->holds()->release($so->id);
        $this->assertEquals(0, $this->row->fresh()->reserved_quantity);
        $this->assertEquals(20, $this->row->fresh()->quantity);
        $this->assertEquals(1, DB::table('nexopos_stock_reservations')->where('sales_document_id', $so->id)->where('status', 'released')->count());
    }

    public function test_sale_cannot_take_reserved_goods_but_owner_invoice_consumes_once(): void
    {
        $this->row->quantity = 2;
        $this->row->save();
        $so = $this->accept($this->quote(), 'invoice');
        $this->holds()->reserve($so->id);
        try {
            app(ProductService::class)->reduceUnitQuantities($this->row->product_id, $this->row->unit_id, 1, 2, $this->branch->id);
            $this->fail('Reserved stock sold');
        } catch (NotAllowedException $e) {
        }
        $other = $this->accept($this->quote(), 'invoice');
        try {
            $this->service->invoice($other->id);
            $this->fail('Other order sold reserved stock');
        } catch (NotAllowedException $e) {
        }
        $this->assertEquals(2, $this->row->fresh()->quantity);
        $order = $this->service->invoice($so->id);
        $this->service->invoice($so->id);
        $this->assertEquals(0, $this->row->fresh()->quantity);
        $this->assertEquals(0, $this->row->fresh()->reserved_quantity);
        $this->assertEquals(1, DB::table('nexopos_stock_reservations')->where('sales_document_id', $so->id)->where('status', 'consumed')->count());
    }

    public function test_over_reservation_rejects_and_cancel_restores_availability(): void
    {
        $this->row->quantity = 3;
        $this->row->save();
        $so = $this->accept($this->quote(), 'dispatch');
        $other = $this->accept($this->quote(), 'dispatch');
        $this->holds()->reserve($so->id);
        try {
            $this->holds()->reserve($other->id);
            $this->fail('Over reserved');
        } catch (ValidationException $e) {
        }
        $this->assertEquals(2, $this->row->fresh()->reserved_quantity);
        $this->service->cancel($so->id);
        $this->holds()->reserve($other->id);
        $this->assertEquals(2, $this->row->fresh()->reserved_quantity);
        $this->assertEquals(3, $this->row->fresh()->quantity);
    }

    public function test_failed_invoice_rolls_back_released_reservation(): void
    {
        $so = $this->accept($this->quote(), 'invoice');
        $this->holds()->reserve($so->id);
        $this->mock(OrdersService::class)->shouldReceive('create')->once()->andThrow(new \RuntimeException('Checkout failed'));
        try {
            $this->service->invoice($so->id);
            $this->fail('Unpaid invoice allowed');
        } catch (\Throwable $e) {
            if ($e instanceof AssertionFailedError) {
                throw $e;
            }
        }
        $this->assertNull($so->fresh()->order_id);
        $this->assertEquals(2, $this->row->fresh()->reserved_quantity);
        $this->assertEquals(20, $this->row->fresh()->quantity);
    }

    public function test_cartons_reserve_base_unit_and_block_count_below_hold(): void
    {
        $group = new UnitGroup;
        $group->name = 'T70 carton';
        $group->author_id = auth()->id();
        $group->save();
        $units = [];
        foreach ([1, 10] as $factor) {
            $u = new Unit;
            foreach (['name' => 'T70-'.$factor, 'identifier' => 't70-'.$factor, 'value' => $factor, 'base_unit' => $factor === 1, 'group_id' => $group->id, 'author_id' => auth()->id()] as $k => $v) {
                $u->$k = $v;
            }$u->save();
            $units[] = $u;
        }
        $p = $this->row->product;
        $p->unit_group = $group->id;
        $p->stock_on_base_unit = true;
        $p->save();
        $base = $this->row;
        $base->unit_id = $units[0]->id;
        $base->quantity = 30;
        $base->save();
        $pack = $base->replicate();
        $pack->unit_id = $units[1]->id;
        $pack->quantity = 0;
        $pack->save();
        $this->row = $pack;
        $so = $this->accept($this->quote(), 'invoice');
        $this->holds()->reserve($so->id);
        $this->assertEquals(20, $base->fresh()->reserved_quantity);
        $this->assertEquals(0, $pack->fresh()->reserved_quantity);
        $this->post('/dashboard/inventory/counts', ['branch_id' => $this->branch->id, 'title' => 'นับสินค้าจอง'])->assertRedirect();
        $count = StockCount::latest('id')->firstOrFail();
        foreach ([['action' => 'add', 'row_id' => $base->id], ['action' => 'save', 'row_id' => $base->id, 'quantity' => 19]] as $data) {
            $this->postJson('/dashboard/inventory/counts/'.$count->id, $data + ['version' => $count->fresh()->version])->assertRedirect();
        }
        $this->postJson('/dashboard/inventory/counts/'.$count->id, ['action' => 'confirm', 'acknowledge' => 1, 'version' => $count->fresh()->version])->assertUnprocessable();
        $this->service->invoice($so->id);
        $this->assertEquals(10, $base->fresh()->quantity);
        $this->assertEquals(0, $base->fresh()->reserved_quantity);
    }

    public function test_multiple_lines_fail_atomically_and_reserved_product_cannot_be_deleted(): void
    {
        $so = $this->accept($this->quote(), 'invoice');
        $other = $this->row->replicate();
        $p = Product::factory()->create(['stock_management' => 'enabled', 'type' => 'materialized', 'unit_group' => $this->row->product->unit_group]);
        $other->product_id = $p->id;
        $other->quantity = 0;
        $other->save();
        $lines = $so->lines;
        $line = $lines[0];
        $line['unit_quantity_id'] = $other->id;
        $line['product_id'] = $p->id;
        $lines[] = $line;
        $so->lines = $lines;
        $so->save();
        try {
            $this->holds()->reserve($so->id);
            $this->fail('Partial hold kept');
        } catch (ValidationException $e) {
        }
        $this->assertEquals(0, $this->row->fresh()->reserved_quantity);
        $this->assertEquals(0, DB::table('nexopos_stock_reservations')->where('sales_document_id', $so->id)->count());
        $other->quantity = 3;
        $other->save();
        $this->holds()->reserve($so->id);
        try {
            app(ProductService::class)->deleteProduct($this->row->product);
            $this->fail('Reserved product deleted');
        } catch (HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }
    }

    public function test_dispatch_uses_hold_and_reserve_requires_permissions_and_branch(): void
    {
        $so = $this->accept($this->quote(), 'dispatch');
        $this->holds()->reserve($so->id);
        $d = SalesDelivery::where('sales_document_id', $so->id)->firstOrFail();
        $this->service->delivery($d->id, ['action' => 'dispatch', 'carrier' => 'รถร้าน', 'note' => 'ทดสอบ', 'acknowledge' => 1]);
        $this->assertEquals(18, $this->row->fresh()->quantity);
        $this->assertEquals(0, $this->row->fresh()->reserved_quantity);
        $this->post('/dashboard/sales/orders/'.$so->id.'/action', ['action' => 'reserve', 'acknowledge' => 1])->assertStatus(422);
        $other = Branch::create(['code' => 'T70B', 'name' => 'สาขาอื่น', 'is_active' => true, 'author_id' => auth()->id()]);
        $staff = $this->attemptGetAnyUserFromRole('nexopos.store.cashier');
        $staff->branch_id = $other->id;
        $staff->save();
        $this->app['auth']->forgetGuards();
        session()->forget('password_hash_web');
        $this->actingAs($staff, 'web');
        Gate::before(fn () => true);
        $this->getJson('/dashboard/sales/reservations?branch_id='.$this->branch->id)->assertForbidden();
        $this->postJson('/dashboard/sales/orders/'.$so->id.'/action',['action' => 'release', 'acknowledge' => 1])->assertForbidden();
    }
}
