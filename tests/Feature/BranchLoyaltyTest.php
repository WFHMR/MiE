<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\Branch;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Services\BranchLoyaltyService;
use App\Services\MemberImportService;
use App\Services\OrdersService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class BranchLoyaltyTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private Branch $branch;

    private int $customer;

    private int $account;

    private array $fields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->attemptGetAnyUserFromRole());
        $this->branch = Branch::create(['code' => 'LOYALTY-TEST', 'name' => 'คะแนนทดสอบ', 'is_active' => true, 'author_id' => auth()->id()]);
        session(['selected_branch_id' => $this->branch->id]);
        $imports = app(MemberImportService::class);
        $id = $imports->preview([['ชื่อ', 'เบอร์โทร', 'คะแนน'], ['ทดสอบคะแนน', '0997654321', '500']], $this->branch->id, CustomerGroup::firstOrFail()->id);
        $imports->confirm($id);
        $account = DB::table('nexopos_loyalty_accounts')->where('branch_id', $this->branch->id)->first();
        $this->customer = (int) $account->customer_id;
        $this->account = (int) $account->id;
        DB::table('nexopos_loyalty_settings')->where('branch_id', 0)->delete();
        DB::table('nexopos_loyalty_settings')->insert(['branch_id' => 0, 'enabled' => true, 'earn_satang' => 10000, 'redeem_points' => 100, 'redeem_satang' => 1000, 'updated_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::factory()->create(['name' => 'ทดสอบคะแนนสินค้า', 'stock_management' => 'disabled', 'type' => 'materialized', 'tax_type' => 'disabled']);
        $unit = Unit::firstOrFail();
        $row = new ProductUnitQuantity;
        $row->product_id = $product->id;
        $row->unit_id = $unit->id;
        $row->branch_id = $this->branch->id;
        $row->quantity = 100;
        $row->sale_price = 200;
        $row->wholesale_price = 200;
        $row->save();
        $this->fields = ['customer_id' => $this->customer, 'branch_id' => $this->branch->id, 'type' => ['identifier' => 'takeaway'], 'discount_type' => 'flat', 'discount' => 10, 'discount_percentage' => 0, 'coupons' => [], 'shipping' => 0, 'subtotal' => 200, 'tax_type' => 'disabled', 'taxes' => [], 'products' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 200, 'unit_id' => $unit->id, 'unit_quantity_id' => $row->id]], 'payments' => [['identifier' => 'cash-payment', 'value' => 190]], 'loyalty_points' => 100, 'loyalty_request_key' => (string) Str::uuid()];
    }

    public function test_refunding_shipping_first_does_not_reduce_product_refund_limit(): void
    {
        $fields = $this->fields;
        $fields['shipping'] = 20;
        $fields['payments'][0]['value'] = 210;
        $service = app(OrdersService::class);
        $order = $service->create($fields)['data']['order'];
        $this->assertEquals(210, $order->total);
        $service->refundOrder($order, ['payment' => ['identifier' => 'cash-payment'], 'products' => [], 'refund_shipping' => true]);
        $this->assertSame(401, $this->balance());
        $product = $order->products()->firstOrFail();
        $service->refundOrder($order, ['payment' => ['identifier' => 'cash-payment'], 'products' => [['id' => $product->id, 'quantity' => 1, 'unit_price' => 190, 'condition' => 'unspoiled', 'description' => 'QA shipping first']], 'refund_shipping' => false]);
        $this->assertSame(500, $this->balance());
    }

    private function withoutPointsAccount(): void
    {
        DB::table('nexopos_loyalty_entries')->where('account_id', $this->account)->delete();
        DB::table('nexopos_loyalty_accounts')->where('customer_id', $this->customer)->delete();
        $this->fields['loyalty_points'] = 0;
        $this->fields['discount'] = 0;
        $this->fields['payments'][0]['value'] = 200;
    }

    public function test_receipt_shows_saved_points_and_separates_current_balance(): void
    {
        $order = app(OrdersService::class)->create($this->fields)['data']['order'];
        $before = $order->fresh()->getAttributes();
        DB::table('nexopos_loyalty_accounts')->where('id', $this->account)->increment('points', 20);
        $html = view('pages.dashboard.orders.templates._receipt-member', ['order' => $order])->render();
        $this->assertStringContainsString('ทดสอบคะแนน', $html);
        $this->assertStringContainsString('คะแนนที่ได้จากบิลนี้: 1', $html);
        $this->assertStringContainsString('คะแนนที่ใช้ในบิลนี้: 100', $html);
        $this->assertStringContainsString('คะแนนคงเหลือหลังรายการ: 401', $html);
        $this->assertStringContainsString('คะแนนคงเหลือปัจจุบันทุกสาขา: 421', $html);
        $this->assertEquals($before, $order->fresh()->getAttributes());
        $this->assertSame(421, $this->balance());
    }

    public function test_existing_customer_is_searchable_without_second_registration(): void
    {
        $this->withoutPointsAccount();
        DB::table('nexopos_users')->where('id', $this->customer)->update(['first_name' => 'ทดสอบสมาชิก', 'last_name' => 'คะแนนอัตโนมัติ', 'phone' => '089-249-8434']);
        foreach (['0892498434', 'คะแนนอัตโนมัติ', 'ทดสอบสมาชิก คะแนนอัตโนมัติ'] as $search) {
            $this->get('/dashboard/customers/branch-points?branch_id='.$this->branch->id.'&q='.urlencode($search))->assertOk()->assertSee('089-249-8434')->assertSee('พร้อมสะสมเมื่อขาย')->assertDontSee('ลงทะเบียนคะแนน</button>', false);
        }
        $this->getJson('/dashboard/customers/branch-points/context?customer_id='.$this->customer)->assertOk()->assertJson(['member' => true, 'points' => 0]);
        $this->assertEquals(0, DB::table('nexopos_loyalty_accounts')->where('customer_id', $this->customer)->count());
    }

    public function test_first_sale_automatically_earns_and_reuses_same_account_across_branches(): void
    {
        $this->withoutPointsAccount();
        app(OrdersService::class)->create($this->fields);
        $this->assertEquals(2, app(BranchLoyaltyService::class)->balance($this->customer));
        $this->switchToAnotherBranch();
        app(OrdersService::class)->create($this->fields);
        $this->assertEquals(4, app(BranchLoyaltyService::class)->balance($this->customer));
        $this->assertEquals(1, DB::table('nexopos_loyalty_accounts')->where('customer_id', $this->customer)->count());
    }

    public function test_disabled_program_and_missing_phone_do_not_auto_enroll(): void
    {
        $this->withoutPointsAccount();
        DB::table('nexopos_loyalty_settings')->where('branch_id', 0)->update(['enabled' => false]);
        app(OrdersService::class)->create($this->fields);
        $this->assertEquals(0, DB::table('nexopos_loyalty_accounts')->where('customer_id', $this->customer)->count());
        DB::table('nexopos_loyalty_settings')->where('branch_id', 0)->update(['enabled' => true]);
        DB::table('nexopos_users')->where('id', $this->customer)->update(['phone' => '']);
        $this->getJson('/dashboard/customers/branch-points/context?customer_id='.$this->customer)->assertOk()->assertJson(['member' => false]);
        app(OrdersService::class)->create($this->fields);
        $this->assertEquals(0, DB::table('nexopos_loyalty_accounts')->where('customer_id', $this->customer)->count());
    }

    public function test_failed_first_sale_does_not_leave_an_account_or_points(): void
    {
        $this->withoutPointsAccount();
        $this->fields['products'][0]['unit_quantity_id'] = 999999999;
        try {
            app(OrdersService::class)->create($this->fields);
            $this->fail('Invalid sale accepted');
        } catch (NotAllowedException $e) {
        }
        $this->assertEquals(0, DB::table('nexopos_loyalty_accounts')->where('customer_id', $this->customer)->count());
    }

    private function balance(): int
    {
        return (int) DB::table('nexopos_loyalty_accounts')->where('id', $this->account)->value('points');
    }

    public function test_sale_redeems_and_earns_once_with_actual_order_service(): void
    {
        $order = app(OrdersService::class)->create($this->fields)['data']['order'];
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertEquals(190, $order->total);
        $this->assertSame(401, $this->balance());
        app(BranchLoyaltyService::class)->settle($order);
        $this->assertSame(401, $this->balance());
    }

    public function test_failed_payment_does_not_spend_points_or_create_order(): void
    {
        $before = Order::count();
        $fields = $this->fields;
        $fields['payments'][0]['value'] = 1;
        try {
            app(OrdersService::class)->create($fields);
            $this->fail('Partial payment accepted with points');
        } catch (NotAllowedException $e) {
            $this->assertSame(500, $this->balance());
            $this->assertSame($before, Order::count());
        }
    }

    public function test_wrong_discount_and_cross_branch_are_rejected(): void
    {
        $fields = $this->fields;
        $fields['discount'] = 100;
        try {
            app(OrdersService::class)->create($fields);
            $this->fail('Forged discount accepted');
        } catch (NotAllowedException $e) {
            $this->assertSame(500, $this->balance());
        }
        session(['selected_branch_id' => 0]);
        $this->expectException(HttpException::class);
        app(OrdersService::class)->create($this->fields);
    }

    public function test_void_restores_spent_points_and_removes_earned_points_once(): void
    {
        $order = app(OrdersService::class)->create($this->fields)['data']['order'];
        app(BranchLoyaltyService::class)->reverse($order, true);
        app(BranchLoyaltyService::class)->reverse($order, true);
        $this->assertSame(500, $this->balance());
    }

    public function test_settings_save_and_branch_page(): void
    {
        $this->post('/dashboard/customers/branch-points', ['branch_id' => $this->branch->id, 'enabled' => 1, 'earn_baht' => '50.00', 'redeem_points' => 20, 'redeem_baht' => '5.00'])->assertRedirect();
        $this->assertEquals(5000, DB::table('nexopos_loyalty_settings')->where('branch_id', 0)->value('earn_satang'));
        $this->get('/dashboard/customers/branch-points?branch_id='.$this->branch->id.'&q=0997654321')->assertOk()->assertSee('ทดสอบคะแนน')->assertSee('500');
    }

    public function test_replay_redemption_and_overspend_are_rejected(): void
    {
        app(OrdersService::class)->create($this->fields);
        $before = Order::count();
        try {
            app(OrdersService::class)->create($this->fields);
            $this->fail('Replay accepted');
        } catch (NotAllowedException $e) {
            $this->assertSame($before, Order::count());
        }
        $fields = $this->fields;
        $fields['loyalty_request_key'] = (string) Str::uuid();
        $fields['loyalty_points'] = 500;
        $fields['discount'] = 50;
        try {
            app(OrdersService::class)->create($fields);
            $this->fail('Overspend accepted');
        } catch (NotAllowedException $e) {
            $this->assertSame(401, $this->balance());
        }
    }

    public function test_hold_and_disabled_settings_do_not_spend(): void
    {
        $fields = $this->fields;
        $fields['payment_status'] = 'hold';
        $fields['payments'] = [];
        try {
            app(OrdersService::class)->create($fields);
            $this->fail('Hold accepted');
        } catch (NotAllowedException $e) {
            $this->assertSame(500, $this->balance());
        }
        DB::table('nexopos_loyalty_settings')->where('branch_id', 0)->update(['enabled' => false]);
        try {
            app(OrdersService::class)->create($this->fields);
            $this->fail('Disabled redemption accepted');
        } catch (NotAllowedException $e) {
            $this->assertSame(500, $this->balance());
        }
    }

    public function test_employee_cannot_change_settings_without_permission_or_read_other_branch(): void
    {
        $staff = $this->attemptGetAnyUserFromRole('nexopos.store.cashier');
        $staff->branch_id = $this->branch->id;
        $staff->save();
        $this->actingAs($staff);
        Gate::define('nexopos.update.rewards', fn () => false);
        $this->postJson('/dashboard/customers/branch-points', ['branch_id' => $this->branch->id])->assertForbidden();
        Gate::define('nexopos.read.customers', fn () => true);
        $this->getJson('/dashboard/customers/branch-points?branch_id=999999')->assertForbidden();
    }

    public function test_enrolling_existing_member_never_resets_balance(): void
    {
        $this->post('/dashboard/customers/branch-points/enroll', ['branch_id' => $this->branch->id, 'customer_id' => $this->customer, 'acknowledge' => 1])->assertRedirect();
        $this->assertSame(500, $this->balance());
        $this->get('/dashboard/customers/'.$this->customer.'/rewards')->assertRedirect();
    }

    public function test_refund_cannot_return_more_cash_than_paid_and_full_refund_restores_points(): void
    {
        $service = app(OrdersService::class);
        $order = $service->create($this->fields)['data']['order'];
        $product = $order->products()->firstOrFail();
        $refund = ['payment' => ['identifier' => 'cash-payment'], 'products' => [['id' => $product->id, 'quantity' => 1, 'unit_price' => 200, 'condition' => 'unspoiled', 'description' => 'QA refund']], 'refund_shipping' => false];
        try {
            $service->refundOrder($order, $refund);
            $this->fail('Over-refund accepted');
        } catch (NotAllowedException $e) {
            $this->assertSame(401, $this->balance());
            $this->assertSame(0, $order->refunds()->count());
        }
        $refund['products'][0]['unit_price'] = 190;
        $service->refundOrder($order, $refund);
        $this->assertSame(500, $this->balance());
    }

    public function test_negative_balance_can_earn_again_at_another_branch(): void
    {
        DB::table('nexopos_loyalty_accounts')->where('id', $this->account)->update(['points' => -5]);
        $this->switchToAnotherBranch();
        $this->fields['loyalty_points'] = 0;
        $this->fields['discount'] = 0;
        $this->fields['payments'][0]['value'] = 200;
        app(OrdersService::class)->create($this->fields);
        $this->assertSame(-3, app(BranchLoyaltyService::class)->balance($this->customer));
    }

    private function switchToAnotherBranch(): Branch
    {
        $branch = Branch::create(['code' => 'SHARED-TEST', 'name' => 'สาขาร่วมคะแนน', 'is_active' => true, 'author_id' => auth()->id()]);
        session(['selected_branch_id' => $branch->id]);
        $unit = ProductUnitQuantity::findOrFail($this->fields['products'][0]['unit_quantity_id']);
        $other = $unit->replicate();
        $other->branch_id = $branch->id;
        $other->save();
        $this->fields['products'][0]['unit_quantity_id'] = $other->id;
        $this->fields['branch_id'] = $branch->id;

        return $branch;
    }

    public function test_member_can_earn_and_redeem_at_a_new_branch_without_enrolling_again(): void
    {
        $other = $this->switchToAnotherBranch();
        $this->getJson('/dashboard/customers/branch-points/context?customer_id='.$this->customer)->assertOk()->assertJson(['points' => 500, 'member' => true, 'enabled' => true]);
        $order = app(OrdersService::class)->create($this->fields)['data']['order'];
        $this->assertEquals($other->id, $order->branch_id);
        $this->assertSame(401, app(BranchLoyaltyService::class)->balance($this->customer));
        $this->assertEquals(1, DB::table('nexopos_loyalty_accounts')->where('customer_id', $this->customer)->count());
        $entry = DB::table('nexopos_loyalty_entries')->where('reference', 'sale:'.$order->id.':earn')->first();
        $this->assertEquals(401, $entry->shared_balance);
        $this->assertEquals($other->id, $entry->branch_id);
        $this->get('/dashboard/customers/branch-points?branch_id='.$other->id.'&account='.$this->account)->assertOk()->assertSee('สาขาร่วมคะแนน')->assertSee('คะแนนสมาชิกทุกสาขา');
        app(BranchLoyaltyService::class)->reverse($order, true);
        $this->assertSame(500, app(BranchLoyaltyService::class)->balance($this->customer));
    }

    public function test_existing_branch_balances_are_combined_without_rewriting_history(): void
    {
        $other = $this->switchToAnotherBranch();
        DB::table('nexopos_loyalty_accounts')->insert(['customer_id' => $this->customer, 'branch_id' => $other->id, 'member_code' => 'LEGACY-CODE', 'points' => 250]);
        $this->assertSame(750, app(BranchLoyaltyService::class)->balance($this->customer));
        $this->get('/dashboard/customers/branch-points?branch_id='.$other->id.'&q=LEGACY-CODE')->assertOk()->assertSee('750');
        $this->fields['loyalty_points'] = 600;
        $this->fields['discount'] = 60;
        $this->fields['payments'][0]['value'] = 140;
        app(OrdersService::class)->create($this->fields);
        $this->assertSame(151, app(BranchLoyaltyService::class)->balance($this->customer));
        $this->post('/dashboard/customers/branch-points/enroll', ['branch_id' => $other->id, 'customer_id' => $this->customer, 'acknowledge' => 1])->assertRedirect();
        $this->assertSame(151, app(BranchLoyaltyService::class)->balance($this->customer));
    }

    public function test_second_branch_cannot_spend_points_already_used_at_first_branch(): void
    {
        $this->fields['loyalty_points'] = 500;
        $this->fields['discount'] = 50;
        $this->fields['payments'][0]['value'] = 150;
        app(OrdersService::class)->create($this->fields);
        $this->assertSame(1, app(BranchLoyaltyService::class)->balance($this->customer));
        $this->switchToAnotherBranch();
        $this->fields['loyalty_request_key'] = (string) Str::uuid();
        $before = Order::count();
        try {
            app(OrdersService::class)->create($this->fields);
            $this->fail('Double spending accepted');
        } catch (NotAllowedException $e) {
            $this->assertSame($before, Order::count());
            $this->assertSame(1, app(BranchLoyaltyService::class)->balance($this->customer));
        }
    }
}
