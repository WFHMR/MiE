<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Services\OrdersService;
use App\Services\SalesWorkflowService;
use App\Services\ScheduledPriceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class ScheduledPriceTest extends TestCase
{
    use DatabaseTransactions,WithAuthentication;

    private $s;

    private $b;

    private $row;

    private $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->attemptGetAnyUserFromRole(), 'web');
        $this->s = app(ScheduledPriceService::class);
        $this->b = Branch::create(['code' => 'SP66', 'name' => 'Promo QA', 'is_active' => true, 'author_id' => auth()->id()]);
        session(['selected_branch_id' => $this->b->id]);
        $this->customer = Customer::firstOrFail();
        $this->customer->credit_limit_amount = 0;
        $this->customer->save();
        ns()->option->set('ns_orders_allow_incomplete', true);
        ns()->option->set('ns_pos_vat', 'disabled');
        $unit = Unit::firstOrFail();
        $p = Product::factory()->create(['name' => 'Promo product QA', 'stock_management' => 'enabled', 'stock_on_base_unit' => false, 'type' => 'materialized', 'unit_group' => $unit->group_id, 'tax_type' => 'disabled', 'tax_group_id' => null]);
        $this->row = new ProductUnitQuantity;
        foreach (['product_id' => $p->id, 'unit_id' => $unit->id, 'branch_id' => $this->b->id, 'quantity' => 20, 'sale_price' => 100, 'sale_price_edit' => 100, 'cogs' => 30] as $k => $v) {
            $this->row->$k = $v;
        }$this->row->save();
    }

    private function promo(array $overrides = []): int
    {
        return $this->s->save(array_merge(['request_key' => (string) Str::uuid(), 'name' => 'โปรทดสอบ', 'unit_quantity_id' => $this->row->id, 'price' => 80, 'starts_at' => now('Asia/Bangkok')->subMinute()->format('Y-m-d\TH:i'), 'ends_at' => now('Asia/Bangkok')->addHour()->format('Y-m-d\TH:i')], $overrides));
    }

    private function fields($price = 80): array
    {
        return ['branch_id' => $this->b->id, 'customer_id' => $this->customer->id, 'type' => ['identifier' => 'takeaway'], 'tax_type' => 'disabled', 'products' => [['product_id' => $this->row->product_id, 'unit_id' => $this->row->unit_id, 'unit_quantity_id' => $this->row->id, 'quantity' => 2, 'unit_price' => $price, 'name' => 'Promo product QA']], 'payments' => []];
    }

    public function test_promo_uses_same_price_other_branch_without_overwriting_catalogue(): void
    {
        $id = $this->promo();
        $other = $this->row->replicate();
        $other->branch_id = 1;
        $other->sale_price = 120;
        $other->save();
        $this->assertEquals(80, $this->s->display($other)->sale_price);
        $this->assertEquals(80, $this->s->display($this->row)->wholesale_price);
        $this->assertEquals(100, $this->row->fresh()->sale_price);
        $this->assertEquals(120, $other->fresh()->sale_price);
        $this->assertEquals($id, $this->s->active($other->product_id, $other->unit_id)->id);
    }

    public function test_interval_includes_start_excludes_end_and_stop_retains_history(): void
    {
        $this->travelTo(now()->startOfMinute());
        $id = $this->promo(['starts_at' => now('Asia/Bangkok')->addMinute()->format('Y-m-d\TH:i'), 'ends_at' => now('Asia/Bangkok')->addMinutes(2)->format('Y-m-d\TH:i')]);
        $this->assertNull($this->s->active($this->row->product_id, $this->row->unit_id));
        $this->travel(1)->minutes();
        $this->assertNotNull($this->s->active($this->row->product_id, $this->row->unit_id));
        $this->travel(1)->minutes();
        $this->assertNull($this->s->active($this->row->product_id, $this->row->unit_id));
        $this->s->stop($id, ['reason' => 'หยุดทดสอบ']);
        $this->assertEquals(1, DB::table('nexopos_scheduled_prices')->where('id', $id)->count());
    }

    public function test_overlap_rejected(): void
    {
        $this->promo();
        $this->expectException(HttpException::class);
        $this->promo();
    }

    public function test_stale_cart_rejected_before_stock_or_sale_write(): void
    {
        $this->promo();
        $count = DB::table('nexopos_orders')->count();
        try {
            app(OrdersService::class)->create($this->fields(100));
            $this->fail('Must reject stale price');
        } catch (NotAllowedException $e) {
            $this->assertEquals($count, DB::table('nexopos_orders')->count());
            $this->assertEquals(20, $this->row->fresh()->quantity);
        }
    }

    public function test_expired_marker_is_rejected(): void
    {
        $id = $this->promo();
        $this->s->stop($id, ['reason' => 'เปลี่ยนโปร']);
        $f = $this->fields();
        $f['products'][0]['scheduled_price_id'] = $id;
        $this->expectException(NotAllowedException::class);
        $this->s->guard($f);
    }

    public function test_valid_price_persists_real_sale_total(): void
    {
        $this->promo();
        $result = app(OrdersService::class)->create($this->fields());
        $this->assertEquals(160, $result['data']['order']->total);
        $this->assertEquals(80, $result['data']['order']->products()->first()->unit_price);
    }

    public function test_quote_uses_promo_and_agreed_price_survives_end(): void
    {
        $id = $this->promo();
        $s = app(SalesWorkflowService::class);
        $q = $s->saveQuote(['request_key' => (string) Str::uuid(), 'branch_id' => $this->b->id, 'customer_id' => $this->customer->id, 'name' => 'QA promo quote', 'valid_until' => now()->addDays(5)->toDateString(), 'lines' => [['unit_quantity_id' => $this->row->id, 'quantity' => 2, 'unit_price' => 100]]]);
        $this->assertEquals(160, $q->total);
        $s->quoteAction($q->id, ['action' => 'issue', 'version' => 1, 'acknowledge' => 1]);
        $so = $s->accept($q->id, ['stock_timing' => 'invoice', 'due_at' => now()->addDays(2)->toDateString(), 'shipping_address' => 'QA', 'acceptance_note' => 'confirmed', 'acknowledge' => 1]);
        $this->s->stop($id, ['reason' => 'สิ้นสุดโปร']);
        $this->assertEquals(160, $s->invoice($so->id)->total);
    }

    public function test_management_page_and_real_product_lookup(): void
    {
        $this->promo();
        $this->get('/dashboard/promotions/scheduled')->assertOk()->assertSee('Promo product QA');
        $this->getJson('/dashboard/promotions/scheduled/products?q=Promo')->assertOk()->assertJsonFragment(['id' => $this->row->id]);
    }

    public function test_promotion_gross_total_with_exclusive_order_tax(): void
    {
        $this->promo();
        $group = new TaxGroup;
        $group->name = 'VAT QA66';
        $group->author_id = auth()->id();
        $group->save();
        $tax = new Tax;
        $tax->name = 'VAT7 QA66';
        $tax->rate = 7;
        $tax->tax_group_id = $group->id;
        $tax->author_id = auth()->id();
        $tax->save();
        $f = $this->fields(round(80 / 1.07, 5));
        $f['tax_group_id'] = $group->id;
        $f['tax_type'] = 'exclusive';
        $f['products'][0]['quantity'] = 1;
        $f['products'][0]['tax_group_id'] = $group->id;
        $f['products'][0]['tax_type'] = 'exclusive';
        $f['taxes'] = [['tax_id' => $tax->id, 'rate' => 7, 'name' => $tax->name, 'tax_value' => round(80 - 80 / 1.07, 2)]];
        $f['total'] = 80;
        $f['tax_value'] = round(80 - 80 / 1.07, 2);
        $result = app(OrdersService::class)->create($f);
        $this->assertEqualsWithDelta(80, $result['data']['order']->total, 0.01);
        $this->assertEqualsWithDelta(round(80 / 1.07, 5), $result['data']['order']->products()->first()->unit_price, 0.001);
        $this->assertEquals(5.23, $result['data']['order']->tax_value);
    }
}
