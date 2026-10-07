<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\StockCount;
use App\Models\Unit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class StockCountTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private Branch $branch;

    private Branch $other;

    private ProductUnitQuantity $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->attemptGetAnyUserFromRole());
        $this->branch = Branch::create(['code' => 'COUNT-T1', 'name' => 'สาขาทดสอบนับ', 'is_active' => true, 'author_id' => auth()->id()]);
        $this->other = Branch::create(['code' => 'COUNT-T2', 'name' => 'สาขาที่สอง', 'is_active' => true, 'author_id' => auth()->id()]);
        $product = Product::factory()->create(['name' => 'สินค้านับทดสอบ', 'stock_management' => 'enabled', 'stock_on_base_unit' => false, 'type' => 'materialized']);
        $this->row = new ProductUnitQuantity;
        $this->row->product_id = $product->id;
        $this->row->unit_id = Unit::firstOrFail()->id;
        $this->row->branch_id = $this->branch->id;
        $this->row->quantity = 10;
        $this->row->cogs = 5;
        $this->row->barcode = 'COUNT-123';
        $this->row->save();
    }

    private function document(): StockCount
    {
        $this->post('/dashboard/inventory/counts', ['branch_id' => $this->branch->id, 'title' => 'นับชั้นทดสอบ'])->assertRedirect();

        return StockCount::latest('id')->firstOrFail();
    }

    private function action(StockCount $count, string $action, array $data = []): TestResponse
    {
        return $this->postJson('/dashboard/inventory/counts/'.$count->id, array_merge(['version' => $count->fresh()->version, 'action' => $action, 'row_id' => $this->row->id], $data));
    }

    public function test_count_zero_and_confirm_once_with_history(): void
    {
        $count = $this->document();
        $this->action($count, 'add')->assertRedirect();
        $this->action($count, 'save', ['quantity' => 0])->assertRedirect();
        $this->assertEquals(10, $this->row->fresh()->quantity);
        $this->get('/dashboard/inventory/counts/'.$count->id)->assertOk()->assertSee('ยอดนับที่บันทึก');
        $this->action($count, 'confirm', ['acknowledge' => 1])->assertRedirect();
        $this->assertEquals(0, $this->row->fresh()->quantity);
        $this->assertEquals('confirmed', $count->fresh()->status);
        $history = ProductHistory::where('product_id', $this->row->product_id)->firstOrFail();
        $this->assertEquals(10, $history->before_quantity);
        $this->assertEquals(0, $history->after_quantity);
        $this->assertEquals($this->branch->id, $history->branch_id);
        $this->action($count, 'confirm', ['acknowledge' => 1])->assertStatus(422);
        $this->assertEquals(1, ProductHistory::where('product_id', $this->row->product_id)->count());
    }

    public function test_employee_cannot_access_another_branch_even_with_confirmation_permission(): void
    {
        $count = $this->document();
        $staff = $this->attemptGetAnyUserFromRole('nexopos.store.cashier');
        $staff->branch_id = $this->other->id;
        $staff->save();
        $this->flushSession();
        $this->actingAs($staff);
        Gate::define('nexopos.stock-counts.work', fn () => true);
        Gate::define('nexopos.stock-counts.confirm', fn () => true);
        $this->getJson('/dashboard/inventory/counts/'.$count->id)->assertForbidden();
        $this->postJson('/dashboard/inventory/counts', ['branch_id' => $this->branch->id, 'title' => 'forged'])->assertForbidden();
        $this->action($count, 'add')->assertForbidden();
        $this->action($count, 'confirm', ['acknowledge' => 1])->assertForbidden();
        $this->get('/dashboard/inventory/counts')->assertOk()->assertDontSee('สาขาทดสอบนับ');
        $staff->branch_id = $this->branch->id;
        $staff->save();
        $this->action($count, 'add')->assertRedirect();
        $this->action($count, 'save', ['quantity' => 8])->assertRedirect();
        $this->action($count, 'confirm', ['acknowledge' => 1])->assertRedirect();
        $this->assertEquals(8, $this->row->fresh()->quantity);
    }

    public function test_stale_stock_blocks_entire_confirmation(): void
    {
        $count = $this->document();
        $this->action($count, 'add')->assertRedirect();
        $this->action($count, 'save', ['quantity' => 5])->assertRedirect();
        $this->row->quantity = 9;
        $this->row->save();
        $this->action($count, 'confirm', ['acknowledge' => 1])->assertStatus(422);
        $this->assertEquals(9, $this->row->fresh()->quantity);
        $this->assertEquals('draft', $count->fresh()->status);
        $this->assertEquals(0, ProductHistory::where('product_id', $this->row->product_id)->count());
    }

    public function test_permissions_empty_negative_and_stale_document_are_rejected(): void
    {
        $count = $this->document();
        $this->action($count, 'confirm', ['acknowledge' => 1])->assertStatus(422);
        $this->action($count, 'add')->assertRedirect();
        $this->action($count, 'add')->assertStatus(422);
        $this->action($count, 'save', ['quantity' => -1])->assertStatus(422);
        $this->action($count, 'save', ['quantity' => 3, 'version' => 1])->assertStatus(422);
        $this->action($count, 'confirm', ['acknowledge' => 1])->assertStatus(422);
        $this->action($count, 'save', ['quantity' => 3])->assertRedirect();
        Gate::define('nexopos.stock-counts.confirm', fn () => false);
        $this->action($count, 'confirm', ['acknowledge' => 1])->assertStatus(403);
        $this->assertEquals(10, $this->row->fresh()->quantity);
        Gate::define('nexopos.stock-counts.work', fn () => false);
        $this->getJson('/dashboard/inventory/counts')->assertStatus(403);
    }

    public function test_cross_branch_stock_id_and_non_base_unit_are_rejected(): void
    {
        $count = $this->document();
        $this->row->branch_id = $this->other->id;
        $this->row->save();
        $this->action($count, 'add')->assertNotFound();
        $this->row->branch_id = $this->branch->id;
        $this->row->save();
        $product = $this->row->product;
        $product->stock_on_base_unit = true;
        $product->save();
        $unit = $this->row->unit;
        $unit->base_unit = false;
        $unit->save();
        $this->action($count, 'add')->assertStatus(422);
        $this->assertEquals(10, $this->row->fresh()->quantity);
    }

    public function test_search_and_list_render(): void
    {
        $this->get('/dashboard/inventory/counts')->assertOk()->assertSee('เปิดใบนับ');
        $count = $this->document();
        $response = $this->get('/dashboard/inventory/counts/'.$count->id.'?q=COUNT-123')->assertOk()->assertSee('สินค้านับทดสอบ');
        if ($path = getenv('STOCK_COUNT_PREVIEW')) {
            file_put_contents($path, $response->getContent());
        }
    }

    public function test_late_conflict_rolls_back_earlier_lines(): void
    {
        $count = $this->document();
        $first = $this->row;
        $second = $first->replicate();
        $second->unit_id = Unit::where('id', '!=', $first->unit_id)->firstOrFail()->id;
        $second->barcode = 'COUNT-SECOND';
        $second->save();
        $this->action($count, 'add')->assertRedirect();
        $this->action($count, 'save', ['quantity' => 4.5])->assertRedirect();
        $this->row = $second;
        $this->action($count, 'add')->assertRedirect();
        $this->action($count, 'save', ['quantity' => 2])->assertRedirect();
        $second->quantity = 8;
        $second->save();
        $this->action($count, 'confirm', ['acknowledge' => 1])->assertStatus(422);
        $this->assertEquals(10, $first->fresh()->quantity);
        $this->assertEquals(8, $second->fresh()->quantity);
        $this->assertEquals(0, ProductHistory::where('product_id', $first->product_id)->count());
        $this->assertEquals('draft', $count->fresh()->status);
    }

    public function test_employee_without_branch_cannot_choose_any_branch(): void
    {
        $staff = $this->attemptGetAnyUserFromRole('nexopos.store.cashier');
        $staff->branch_id = null;
        $staff->save();
        $this->flushSession();
        $this->actingAs($staff);
        Gate::define('nexopos.stock-counts.work', fn () => true);
        $this->postJson('/dashboard/inventory/counts', ['branch_id' => $this->branch->id, 'title' => 'forged'])->assertForbidden();
        $this->get('/dashboard/inventory/counts')->assertOk()->assertSee('ยังไม่มีสาขาที่ตรวจนับได้');
    }
}
