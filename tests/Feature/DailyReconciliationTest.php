<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\Order;
use App\Models\User;
use App\Services\DailyReconciliationService;
use App\Services\OrdersService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class DailyReconciliationTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private function order( string $status = 'paid', int $branch = 1 ): Order
    {
        $this->attemptAuthenticate();
        $o = new Order;
        $o->code = 'QA57-' . uniqid();
        $o->branch_id = $branch;
        $o->author_id = User::first()->id;
        $o->customer_id = 0;
        $o->type = 'takeaway';
        $o->payment_status = $status;
        $o->total = 560;
        $o->tendered = 333;
        $o->change = -227;
        $o->created_at = '2030-01-15 12:00:00';
        $o->save();

        return $o;
    }

    public function test_all_statuses_remain_visible_and_branch_isolated(): void
    {
        foreach ( ['paid', 'partially_paid', 'partially_refunded', 'refunded', 'order_void', 'hold'] as $status ) {
            $this->order( $status );
        }
        $this->order( 'paid', 2 );
        $r = app( DailyReconciliationService::class )->report( '2030-01-15', 1 );
        $this->assertCount( 6, $r['rows'] );
        $this->assertEquals( 1680, $r['sales'] );
        $this->assertCount( 1, app( DailyReconciliationService::class )->report( '2030-01-15', 2 )['rows'] );
    }

    public function test_partial_receipt_flags_shortage_without_mutating_status(): void
    {
        $o = $this->order( 'partially_paid' );
        DB::table( 'nexopos_orders_payments' )->insert( ['order_id' => $o->id, 'value' => 333, 'author_id' => $o->author_id, 'identifier' => 'cash-payment', 'created_at' => '2030-01-15 12:00:00', 'updated_at' => '2030-01-15 12:00:00'] );
        $r = app( DailyReconciliationService::class )->report( '2030-01-15', 1 );
        $this->assertEquals( 227, $r['outstanding'] );
        $this->assertContains( 'ยังรับเงินไม่ครบ', $r['rows'][0]['flags'] );
        $this->assertSame( 'partially_paid', $o->fresh()->payment_status );
    }

    public function test_delete_guard_runs_before_payments_are_removed(): void
    {
        $o = $this->order();
        DB::table( 'nexopos_orders_payments' )->insert( ['order_id' => $o->id, 'value' => 333, 'author_id' => $o->author_id, 'identifier' => 'cash-payment'] );
        try {
            app( OrdersService::class )->deleteOrder( $o );
            $this->fail( 'Paid order deleted' );
        } catch ( NotAllowedException $e ) {
            $this->assertStringContainsString( 'ลบไม่ได้', $e->getMessage() );
        }
        $this->assertNotNull( $o->fresh() );
        $this->assertEquals( 333, $o->payments()->sum( 'value' ) );
    }

    public function test_status_changes_and_held_deletion_leave_actor_and_snapshot(): void
    {
        $o = $this->order();
        $o->payment_status = 'order_void';
        $o->voidance_reason = 'QA reason';
        $o->save();
        $a = DB::table( 'nexopos_order_audits' )->where( 'order_id', $o->id )->where( 'action', 'updated' )->first();
        $this->assertNotNull( $a );
        $this->assertNotNull( $a->actor_id );
        $this->assertSame( 'paid', json_decode( $a->before_data, true )['payment_status'] );
        $this->assertSame( 'order_void', json_decode( $a->after_data, true )['payment_status'] );
        $held = $this->order( 'hold' );
        $held->delete();
        $this->assertDatabaseHas( 'nexopos_order_audits', ['order_id' => $held->id, 'action' => 'deleted'] );
    }

    public function test_page_renders_for_admin_with_explanations(): void
    {
        $this->order();
        $this->actingAs( $this->attemptGetAnyUserFromRole( 'admin' ), 'web' );
        $this->withSession( ['selected_branch_id' => 1] )->get( '/dashboard/reports/daily-reconciliation?date=2030-01-15' )->assertOk()->assertSee( 'กระทบยอดบิลรายวัน' )->assertSee( 'ยังรับเงินไม่ครบ' );
        $this->withSession( ['selected_branch_id' => 1] )->get( '/dashboard/reports/daily-reconciliation?date=not-a-date' )->assertSessionHasErrors( 'date' );
        $this->withSession( ['selected_branch_id' => 1] )->get( '/dashboard/reports/daily-reconciliation?date=' )->assertOk();
    }

    public function test_sequence_gaps_exclude_numbers_used_by_another_branch(): void
    {
        foreach ( [[1, 1], [2, 2], [4, 1]] as [$sequence,$branch] ) {
            $o = $this->order( 'paid', $branch );
            $o->code = '300115-' . str_pad( (string) $sequence, 3, '0', STR_PAD_LEFT );
            $o->save();
        }
        $this->assertSame( ['300115-003'], app( DailyReconciliationService::class )->report( '2030-01-15', 1 )['gaps'] );
    }

    public function test_refunded_bill_keeps_net_sales_and_flags_recomputed_change(): void
    {
        $o = $this->order( 'partially_refunded' );
        $o->total = 5712.50;
        $o->tendered = 6003;
        $o->change = 290.50;
        $o->save();
        DB::table( 'nexopos_orders_payments' )->insert( ['order_id' => $o->id, 'value' => 6003, 'author_id' => $o->author_id, 'identifier' => 'cash-payment'] );
        DB::table( 'nexopos_orders_refunds' )->insert( ['order_id' => $o->id, 'total' => 90, 'shipping' => 0, 'author_id' => $o->author_id, 'payment_method' => 'cash-payment'] );
        $r = app( DailyReconciliationService::class )->report( '2030-01-15', 1 );
        $this->assertEquals( 5712.50, $r['sales'] );
        $this->assertEquals( 0, $r['outstanding'] );
        $this->assertContains( 'ช่องเงินทอนเดิมไม่ตรงหลังคืนสินค้า ต้องตรวจใบเสร็จ', $r['rows'][0]['flags'] );
    }

    public function test_guest_cannot_open_reconciliation(): void
    {
        $this->get( '/dashboard/reports/daily-reconciliation' )->assertRedirect();
    }
}
