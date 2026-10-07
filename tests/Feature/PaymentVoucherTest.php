<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PaymentVoucher;
use App\Models\Procurement;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\TransactionAccount;
use App\Models\TransactionActionRule;
use App\Models\TransactionHistory;
use App\Services\ProviderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class PaymentVoucherTest extends TestCase
{
    use DatabaseTransactions,WithAuthentication;

    private Branch $branch;

    private Provider $provider;

    private Procurement $receipt;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs( $this->attemptGetAnyUserFromRole(), 'web' );
        $this->branch = Branch::create( ['code' => 'PV-TEST', 'name' => 'สาขาใบสำคัญจ่าย', 'is_active' => true, 'author_id' => auth()->id()] );
        $this->provider = Provider::create( ['first_name' => 'ซัพพลายเออร์ทดสอบ', 'code' => '0000123', 'author_id' => auth()->id()] );
        $this->receipt = Procurement::withoutEvents( function () {
            $r = new Procurement;
            foreach ( ['branch_id' => $this->branch->id, 'provider_id' => $this->provider->id, 'author_id' => auth()->id(), 'name' => 'ทดสอบจ่าย', 'invoice_reference' => '0000456', 'invoice_date' => '2026-09-01', 'cost' => 107, 'tax_value' => 7, 'value' => 199, 'delivery_status' => 'stocked', 'payment_status' => 'unpaid'] as $k => $v ) {
                $r->$k = $v;
            }$r->save();

            return $r;
        } );
        $accounts = [];
        foreach ( ['expenses', 'assets'] as $category ) {
            $a = new TransactionAccount;
            $a->name = 'PV ' . $category;
            $a->category_identifier = $category;
            $a->account = 'PV-' . $category;
            $a->author_id = auth()->id();
            $a->save();
            $accounts[] = $a;
        }
        DB::table( 'nexopos_transactions_actions_rules' )->whereIn( 'on', ['procurement_paid', 'procurement_from_unpaid_to_paid'] )->delete();
        foreach ( ['procurement_paid', 'procurement_from_unpaid_to_paid'] as $on ) {
            TransactionActionRule::create( ['on' => $on, 'account_id' => $accounts[0]->id, 'offset_account_id' => $accounts[1]->id, 'action' => 'increase', 'do' => 'decrease'] );
        }
        $this->payload = ['request_key' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'provider_id' => $this->provider->id, 'receipts' => [$this->receipt->id], 'mode' => 'payment', 'paid_at' => '2026-09-01', 'method' => 'transfer', 'reference' => 'ธนาคารทดสอบ REF-001'];
        app( ProviderService::class )->computeSummary( $this->provider );
    }

    private function draft( array $changes = [] ): PaymentVoucher
    {
        $response = $this->post( '/dashboard/payment-vouchers', array_merge( $this->payload, $changes ) )->assertRedirect();

        return PaymentVoucher::latest( 'id' )->firstOrFail();
    }

    public function test_draft_confirm_replay_and_print_preserve_stock_and_pay_exactly_once(): void
    {
        $stock = ProductUnitQuantity::orderBy( 'id' )->get()->toJson();
        $historyCount = ProductHistory::count();
        $ledger = TransactionHistory::count();
        $v = $this->draft( ['total' => 1, 'amount' => 1] );
        $this->assertSame( 'unpaid', $this->receipt->fresh()->payment_status );
        $this->assertEquals( 107, $v->total );
        $this->assertEquals( 107, $this->provider->fresh()->amount_due );
        $this->get( '/dashboard/payment-vouchers/' . $v->id )->assertOk()->assertSee( '0000456' )->assertSee( 'ฉบับร่าง' );
        $this->post( '/dashboard/payment-vouchers', $this->payload )->assertRedirect();
        $this->assertSame( 1, PaymentVoucher::where( 'request_key', $this->payload['request_key'] )->count() );
        $this->postJson( '/dashboard/payment-vouchers/' . $v->id . '/confirm', [] )->assertUnprocessable();
        $this->post( '/dashboard/payment-vouchers/' . $v->id . '/confirm', ['acknowledge' => 1] )->assertRedirect();
        $this->assertSame( 'paid', $this->receipt->fresh()->payment_status );
        $this->assertSame( 'confirmed', $v->fresh()->status );
        $this->assertEquals( 0, $this->provider->fresh()->amount_due );
        $this->assertEquals( 107, $this->provider->fresh()->amount_paid );
        $this->assertSame( $ledger + 2, TransactionHistory::count() );
        $rows = TransactionHistory::where( 'procurement_id', $this->receipt->id )->get();
        $this->assertEquals( 107, $rows->where( 'operation', 'debit' )->sum( 'value' ) );
        $this->assertEquals( 107, $rows->where( 'operation', 'credit' )->sum( 'value' ) );
        $this->assertEquals( auth()->id(), $v->fresh()->confirmed_by );
        $this->post( '/dashboard/payment-vouchers/' . $v->id . '/confirm', ['acknowledge' => 1] )->assertRedirect();
        $this->assertSame( $ledger + 2, TransactionHistory::count() );
        $this->assertSame( $stock, ProductUnitQuantity::orderBy( 'id' )->get()->toJson() );
        $this->assertSame( $historyCount, ProductHistory::count() );
        $this->get( '/dashboard/payment-vouchers/' . $v->id )->assertOk()->assertSee( 'ยืนยันการจ่ายแล้ว' )->assertSee( '107.00' );
        $this->postJson( '/dashboard/payment-vouchers/' . $v->id . '/cancel' )->assertUnprocessable();
    }

    public function test_recorded_paid_receipt_creates_proof_without_ledger_and_rejects_second_voucher(): void
    {
        DB::table( 'nexopos_procurements' )->where( 'id', $this->receipt->id )->update( ['payment_status' => 'paid'] );
        $count = TransactionHistory::count();
        $v = $this->draft( ['mode' => 'recorded'] );
        $other = $this->draft( ['mode' => 'recorded', 'request_key' => (string) Str::uuid()] );
        $this->post( '/dashboard/payment-vouchers/' . $v->id . '/confirm', ['acknowledge' => 1] )->assertRedirect();
        $this->assertSame( $count, TransactionHistory::count() );
        $this->postJson( '/dashboard/payment-vouchers/' . $other->id . '/confirm', ['acknowledge' => 1] )->assertUnprocessable();
    }

    public function test_stale_amount_or_invalid_accounting_rolls_back_everything(): void
    {
        $v = $this->draft();
        DB::table( 'nexopos_procurements' )->where( 'id', $this->receipt->id )->update( ['cost' => 200] );
        $this->postJson( '/dashboard/payment-vouchers/' . $v->id . '/confirm', ['acknowledge' => 1] )->assertUnprocessable();
        DB::table( 'nexopos_procurements' )->where( 'id', $this->receipt->id )->update( ['cost' => 107] );
        DB::table( 'nexopos_transactions_actions_rules' )->where( 'on', 'procurement_paid' )->delete();
        $ledger = TransactionHistory::count();
        $this->postJson( '/dashboard/payment-vouchers/' . $v->id . '/confirm', ['acknowledge' => 1] )->assertUnprocessable();
        $this->assertSame( 'draft', $v->fresh()->status );
        $this->assertSame( 'unpaid', $this->receipt->fresh()->payment_status );
        $this->assertSame( $ledger, TransactionHistory::count() );
        $this->assertFalse( DB::table( 'nexopos_payment_voucher_receipts' )->where( 'voucher_id', $v->id )->exists() );
    }

    public function test_multiple_bills_roll_back_as_one_transaction_when_second_account_rule_fails(): void
    {
        $second = Procurement::withoutEvents( function () {
            $d = $this->receipt->replicate();
            $d->save();

            return $d;
        } );
        TransactionHistory::withoutEvents( function () use ( $second ): void {
            $h = new TransactionHistory;
            $h->name = 'Prior debt';
            $h->procurement_id = $second->id;
            $h->author_id = auth()->id();
            $h->transaction_account_id = TransactionAccount::firstOrFail()->id;
            $h->value = 107;
            $h->operation = 'credit';
            $h->status = 'active';
            $h->type = 'direct';
            $h->trigger_date = now();
            $h->save();
        } );
        $v = $this->draft( ['receipts' => [$this->receipt->id, $second->id]] );
        $this->assertEquals( 214, $v->total );
        DB::table( 'nexopos_transactions_actions_rules' )->where( 'on', 'procurement_from_unpaid_to_paid' )->delete();
        $count = TransactionHistory::count();
        $this->postJson( '/dashboard/payment-vouchers/' . $v->id . '/confirm', ['acknowledge' => 1] )->assertUnprocessable();
        $this->assertSame( $count, TransactionHistory::count() );
        $this->assertSame( 'unpaid', $this->receipt->fresh()->payment_status );
        $this->assertSame( 'unpaid', $second->fresh()->payment_status );
        $this->assertSame( 'draft', $v->fresh()->status );
        $this->assertFalse( DB::table( 'nexopos_payment_voucher_receipts' )->where( 'voucher_id', $v->id )->exists() );
    }

    public function test_paid_by_other_voucher_and_legacy_shortcut_cannot_duplicate_payment(): void
    {
        $one = $this->draft();
        $two = $this->draft( ['request_key' => (string) Str::uuid()] );
        $this->post( '/dashboard/payment-vouchers/' . $one->id . '/confirm', ['acknowledge' => 1] )->assertRedirect();
        $count = TransactionHistory::count();
        $this->postJson( '/dashboard/payment-vouchers/' . $two->id . '/confirm', ['acknowledge' => 1] )->assertUnprocessable();
        $this->assertSame( $count, TransactionHistory::count() );
        $this->postJson( '/api/procurements/' . $this->receipt->id . '/set-as-paid' )->assertStatus( 403 );
        $this->assertSame( $count, TransactionHistory::count() );
    }

    public function test_scope_permissions_cancel_and_ineligible_documents(): void
    {
        $v = $this->draft();
        $staff = $this->attemptGetAnyUserFromRole( 'nexopos.store.cashier' );
        $staff->branch_id = $this->branch->id;
        $staff->save();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs( $staff, 'web' );
        Gate::define( 'nexopos.read.procurements', fn() => true );
        Gate::define( 'nexopos.update.procurements', fn() => false );
        $this->get( '/dashboard/payment-vouchers/' . $v->id )->assertOk();
        $this->postJson( '/dashboard/payment-vouchers/' . $v->id . '/confirm', ['acknowledge' => 1] )->assertForbidden();
        Gate::define( 'nexopos.update.procurements', fn() => true );
        $staff->branch_id = 99999;
        $staff->save();
        $this->getJson( '/dashboard/payment-vouchers/' . $v->id )->assertForbidden();
        $staff->branch_id = $this->branch->id;
        $staff->save();
        $this->post( '/dashboard/payment-vouchers/' . $v->id . '/cancel' )->assertRedirect();
        $this->postJson( '/dashboard/payment-vouchers/' . $v->id . '/confirm', ['acknowledge' => 1] )->assertUnprocessable();
        DB::table( 'nexopos_procurements' )->where( 'id',$this->receipt->id )->update( ['trial_archived_at' => now()] );
        $this->postJson( '/dashboard/payment-vouchers',array_merge( $this->payload,['request_key' => (string) Str::uuid()] ) )->assertUnprocessable();
    }
}
