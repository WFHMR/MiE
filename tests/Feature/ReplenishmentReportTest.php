<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use App\Models\UserAttribute;
use App\Services\ReplenishmentReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class ReplenishmentReportTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private Branch $branch;

    private Product $product;

    private Unit $piece;

    private Unit $pack;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->attemptGetAnyUserFromRole(), 'web');
        $this->branch = Branch::create(['code' => 'T68', 'name' => 'Replenishment QA', 'is_active' => true, 'author_id' => auth()->id()]);
        $group = UnitGroup::create(['name' => 'T68', 'author_id' => auth()->id()]);
        $this->piece = Unit::create(['name' => 'piece', 'identifier' => 't68-piece', 'group_id' => $group->id, 'value' => 1, 'base_unit' => true, 'author_id' => auth()->id()]);
        $this->pack = Unit::create(['name' => 'pack', 'identifier' => 't68-pack', 'group_id' => $group->id, 'value' => 10, 'base_unit' => false, 'author_id' => auth()->id()]);
        $this->product = Product::factory()->create(['name' => '=Dangerous CSV', 'category_id' => DB::table('nexopos_products_categories')->value('id'), 'unit_group' => $group->id, 'stock_on_base_unit' => true, 'stock_management' => 'enabled', 'type' => 'materialized']);
        foreach ([$this->piece, $this->pack] as $unit) {
            $row = new ProductUnitQuantity;
            foreach (['product_id' => $this->product->id, 'unit_id' => $unit->id, 'branch_id' => $this->branch->id, 'quantity' => $unit->base_unit ? 5 : 0] as $key => $value) {
                $row->$key = $value;
            }
            $row->save();
        }
    }

    private function sale(string $date, float $quantity, ?Unit $unit = null, string $status = Order::PAYMENT_PAID, ?int $branch = null): void
    {
        $id = DB::table('nexopos_orders')->insertGetId(['code' => 'T68-'.uniqid(), 'branch_id' => $branch ?? $this->branch->id, 'type' => 'takeaway', 'payment_status' => $status, 'customer_id' => auth()->id(), 'author_id' => auth()->id(), 'created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00']);
        $unitId = ($unit ?? $this->piece)->id;
        $quantityId = ProductUnitQuantity::where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->where('unit_id', $unitId)->value('id');
        DB::table('nexopos_orders_products')->insert(['order_id' => $id, 'product_id' => $this->product->id, 'unit_id' => $unitId, 'unit_quantity_id' => $quantityId, 'product_category_id' => $this->product->category_id, 'name' => $this->product->name, 'quantity' => $quantity]);
    }

    private function report(string $basis = 'week', bool $whole = true): array
    {
        return app(ReplenishmentReportService::class)->build($this->branch->id, '2026-09-30', $basis, 7, 0, 0, $whole);
    }

    public function test_week_windows_conversion_and_zero_sales_days(): void
    {
        $this->sale('2026-09-30', 2, $this->pack);
        $this->sale('2026-09-24', 1);
        $this->sale('2026-09-23', 7);
        $this->sale('2026-09-16', 100);
        $data = $this->report();
        $this->assertCount(1, $data['rows']);
        $r = $data['rows'][0];
        $this->assertEquals(21, $r['totals']['week']);
        $this->assertEquals(7, $r['totals']['previous']);
        $this->assertEquals(3, $r['average']);
        $this->assertEquals(200, $r['wow']);
        $this->assertEquals(16, $r['suggested']);
        $this->assertEquals(128, $r['totals'][1]);
        $this->assertEquals(30, $data['periods'][1]['days']);
    }

    public function test_excludes_void_hold_other_branches_and_future_bills(): void
    {
        foreach ([Order::PAYMENT_VOID, Order::PAYMENT_HOLD, Order::PAYMENT_REFUNDED] as $status) {
            $this->sale('2026-09-30', 100, null, $status);
        }
        $this->sale('2026-09-30', 100, null, Order::PAYMENT_PAID, 999999);
        $this->sale('2026-10-01', 100);
        $this->sale('2026-09-29', 2, null, Order::PAYMENT_UNPAID);
        $r = $this->report()['rows'][0];
        $this->assertEquals(2, $r['totals']['week']);
        $this->assertEquals(0, $r['suggested']);
    }

    public function test_remaining_quantity_after_refund_is_not_subtracted_twice(): void
    {
        $this->sale('2026-09-30', 3);
        DB::table('nexopos_orders_products')->where('product_id', $this->product->id)->update(['status' => 'returned']);
        $this->assertEquals(3, $this->report()['rows'][0]['totals']['week']);
    }

    public function test_no_history_is_unknown_not_an_order_recommendation(): void
    {
        $r = $this->report()['rows'][0];
        $this->assertNull($r['suggested']);
        $this->assertNull($r['wow']);
        $this->assertNull($r['coverage']);
    }

    public function test_decimal_rounding_and_independent_unit_stock(): void
    {
        $this->product->stock_on_base_unit = false;
        $this->product->save();
        $this->sale('2026-09-30', 1.25, $this->pack);
        $data = $this->report('week', false);
        $this->assertCount(2, $data['rows']);
        $r = $data['rows']->firstWhere('unit', 'pack');
        $this->assertEquals(1.25, $r['suggested']);
        $this->assertEquals(2, $this->report()['rows']->firstWhere('unit', 'pack')['suggested']);
    }

    public function test_page_export_validation_and_permission(): void
    {
        $url = '/dashboard/reports/replenishment?branch_id='.$this->branch->id.'&as_of=2026-09-30';
        $this->get($url)->assertOk()->assertSee('Replenishment QA')->assertSee('จำนวนเติม');
        $this->get($url.'&basis=&q=&cover=&lead=')->assertOk();
        $response = $this->get($url.'&export=csv');
        $response->assertOk();
        $this->assertStringContainsString("'=Dangerous CSV", $response->streamedContent());
        $this->getJson($url.'&cover=-1')->assertUnprocessable();
        Gate::define('nexopos.reports.sales', fn () => false);
        $this->get($url)->assertForbidden();
    }

    public function test_month_boundaries_and_lead_buffer_do_not_mutate_stock(): void
    {
        $this->sale('2026-04-01', 10);
        $this->sale('2026-03-31', 999);
        $this->sale('2026-07-01', 20);
        $this->sale('2026-09-01', 30);
        $before = ProductUnitQuantity::where('product_id', $this->product->id)->pluck('quantity', 'id')->all();
        $data = app(ReplenishmentReportService::class)->build($this->branch->id, '2026-09-30', '1', 7, 2, 1, true);
        $r = $data['rows'][0];
        $this->assertEquals(30, $r['totals'][1]);
        $this->assertEquals(50, $r['totals'][3]);
        $this->assertEquals(60, $r['totals'][6]);
        $this->assertEquals(5, $r['suggested']);
        $this->assertSame($before, ProductUnitQuantity::where('product_id', $this->product->id)->pluck('quantity', 'id')->all());
    }

    public function test_employee_cannot_read_or_export_another_branch(): void
    {
        $u = User::factory()->make(['username' => 'qa-t68', 'branch_id' => $this->branch->id, 'active' => true]);
        unset($u->email_verified_at);
        $u->save();
        $u->assignRole('nexopos.store.cashier');
        $attribute = new UserAttribute;
        $attribute->user_id = $u->id;
        $attribute->language = 'en';
        $attribute->save();
        $this->actingAs($u, 'web');
        foreach (['nexopos.read.procurements', 'nexopos.reports.sales', 'nexopos.read.products'] as $permission) {
            Gate::define($permission, fn () => true);
        }
        $this->get('/dashboard/reports/replenishment?branch_id='.$this->branch->id)->assertOk();
        $this->get('/dashboard/reports/replenishment?branch_id=999999')->assertForbidden();
        $this->get('/dashboard/reports/replenishment?branch_id=999999&export=csv')->assertForbidden();
    }
}
