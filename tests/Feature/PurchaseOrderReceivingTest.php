<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\PurchaseOrder;
use App\Models\TransactionHistory;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\ProcurementService;
use App\Services\ProviderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class PurchaseOrderReceivingTest extends TestCase
{
    use DatabaseTransactions,WithAuthentication;

    private Branch $branch;

    private Branch $other;

    private ProductUnitQuantity $row;

    private Provider $provider;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs( $this->attemptGetAnyUserFromRole() );
        $this->branch = Branch::create( ['code' => 'PO-T1', 'name' => 'สาขารับสินค้า', 'is_active' => true, 'author_id' => auth()->id()] );
        $this->other = Branch::create( ['code' => 'PO-T2', 'name' => 'สาขาอื่น', 'is_active' => true, 'author_id' => auth()->id()] );
        $this->provider = new Provider;
        $this->provider->first_name = 'ผู้ขายทดสอบใบสั่งซื้อ';
        $this->provider->author_id = auth()->id();
        $this->provider->save();
        $unit = Unit::firstOrFail();
        $product = Product::factory()->create( ['provider_id' => $this->provider->id, 'name' => 'สินค้าสั่งซื้อทดสอบ', 'stock_management' => 'enabled', 'stock_on_base_unit' => false, 'type' => 'materialized', 'unit_group' => $unit->group_id] );
        $this->row = new ProductUnitQuantity;
        $this->row->product_id = $product->id;
        $this->row->unit_id = $unit->id;
        $this->row->branch_id = $this->branch->id;
        $this->row->quantity = 10;
        $this->row->save();
        $this->payload = ['name' => 'ใบสั่งซื้อทดสอบ', 'general' => ['provider_id' => $this->provider->id, 'branch_id' => $this->branch->id, 'purchase_order_number' => 'PO-TEST', 'delivery_time' => '2026-09-01', 'automatic_approval' => 1, 'delivery_status' => 'delivered', 'payment_status' => 'paid'], 'products' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => 10, 'gross_purchase_price' => 20, 'net_purchase_price' => 20, 'purchase_price' => 20, 'tax_type' => 'inclusive', 'tax_group_id' => 0, 'tax_value' => 0, 'total_purchase_price' => 200]]];
    }

    public function test_search_filters_supplier_and_branch_and_matches_unit_barcode(): void
    {
        $this->row->barcode = 'PACK-T25';
        $this->row->save();
        $otherRow = $this->row->replicate();
        $otherRow->branch_id = $this->other->id;
        $otherRow->quantity = 987;
        $otherRow->save();
        $query = ['search' => 'PACK-T25', 'provider_id' => $this->provider->id, 'branch_id' => $this->branch->id];
        $this->postJson( '/api/purchase-orders/search', $query )->assertOk()->assertJsonCount( 1 )
            ->assertJsonPath( '0.matched_unit_id', $this->row->unit_id )
            ->assertJsonCount( 1, '0.unit_quantities' )->assertJsonPath( '0.unit_quantities.0.quantity', 10 );
        $query['branch_id'] = $this->other->id;
        $this->postJson( '/api/purchase-orders/search', $query )->assertOk()->assertJsonPath( '0.unit_quantities.0.quantity', 987 );
        $product = $this->row->product;
        $product->provider_id = null;
        $product->save();
        $this->postJson( '/api/purchase-orders/search', $query )->assertOk()->assertExactJson( [] );
        $this->postJson( '/api/purchase-orders', $this->payload )->assertUnprocessable();
    }

    public function test_search_requires_supplier_and_denies_staff_other_branch(): void
    {
        $this->postJson( '/api/purchase-orders/search', ['search' => 'test', 'branch_id' => $this->branch->id] )->assertUnprocessable();
        $staff = $this->attemptGetAnyUserFromRole( 'nexopos.store.cashier' );
        $staff->branch_id = $this->branch->id;
        $staff->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs( $staff, 'web' );
        Gate::before( fn () => true );
        $this->postJson( '/api/purchase-orders/search', ['search' => 'test', 'provider_id' => $this->provider->id, 'branch_id' => $this->other->id] )->assertForbidden();
    }

    public function test_search_restores_unit_purchase_price_tax_mode_and_default_without_other_branch_cost(): void
    {
        $this->payload['products'][0]['tax_type'] = 'exclusive';
        $this->payload['products'][0]['net_purchase_price'] = 20;
        $this->payload['products'][0]['gross_purchase_price'] = 21.4;
        $this->payload['products'][0]['purchase_price'] = 21.4;
        $this->payload['products'][0]['tax_value'] = 14;
        $this->payload['products'][0]['total_purchase_price'] = 214;
        $po = $this->order();
        $this->countAndApprove( $po, 2 )->assertRedirect();
        $product = $this->row->product;
        $query = ['search' => $product->name, 'provider_id' => $this->provider->id, 'branch_id' => $this->branch->id];
        $this->postJson( '/api/purchase-orders/search', $query )->assertOk()
            ->assertJsonPath( '0.default_purchase_unit_id', $this->row->unit_id )
            ->assertJsonPath( '0.unit_quantities.0.last_purchase_price', 20 )
            ->assertJsonPath( '0.unit_quantities.0.purchase_tax_type', 'exclusive' );
        $otherRow = $this->row->replicate();
        $otherRow->branch_id = $this->other->id;
        $otherRow->save();
        $query['branch_id'] = $this->other->id;
        $this->postJson( '/api/purchase-orders/search', $query )->assertOk()->assertJsonPath( '0.unit_quantities.0.last_purchase_price', null );
        $otherRow->cogs = 77;
        $otherRow->save();
        $this->postJson( '/api/purchase-orders/search', $query )->assertOk()->assertJsonPath( '0.unit_quantities.0.last_purchase_price', 77 )->assertJsonPath( '0.unit_quantities.0.purchase_tax_type', 'inclusive' );
    }

    public function test_receipt_without_count_acknowledgement_cannot_change_stock(): void
    {
        $po = $this->order();
        $this->countAndApprove( $po, 2, ['acknowledge' => 0] )->assertUnprocessable();
        $this->assertEquals( 10, $this->row->fresh()->quantity );
        $this->assertEquals( 0, Procurement::where( 'purchase_order_id', $po->id )->count() );
    }

    public function test_legacy_delivered_and_scheduled_documents_never_auto_stock(): void
    {
        $legacy = new Procurement;
        $legacy->name = 'legacy trial';
        $legacy->provider_id = $this->provider->id;
        $legacy->author_id = auth()->id();
        $legacy->delivery_status = 'delivered';
        $legacy->automatic_approval = true;
        $legacy->delivery_time = now()->subDay();
        Procurement::withoutEvents( fn () => $legacy->save() );
        app( ProcurementService::class )->handleProcurement( $legacy );
        app( ProcurementService::class )->stockAwaitingProcurements();
        $this->assertEquals( 'pending', $legacy->fresh()->delivery_status );
        $this->assertEquals( 10, $this->row->fresh()->quantity );
        $this->postJson( '/api/procurements', $this->payload )->assertUnprocessable();
    }

    public function test_archive_trial_preserves_stock_status_and_history_and_can_restore(): void
    {
        $legacy = new Procurement;
        $legacy->name = 'trial to archive';
        $legacy->provider_id = $this->provider->id;
        $legacy->author_id = auth()->id();
        $legacy->delivery_status = 'stocked';
        Procurement::withoutEvents( fn () => $legacy->save() );
        $histories = ProductHistory::count();
        $this->post( '/dashboard/purchase-trials/' . $legacy->id, ['action' => 'archive', 'acknowledge' => 1] )->assertRedirect();
        $this->assertNotNull( $legacy->fresh()->trial_archived_at );
        $this->assertEquals( 'stocked', $legacy->fresh()->delivery_status );
        $this->assertEquals( 10, $this->row->fresh()->quantity );
        $this->assertEquals( $histories, ProductHistory::count() );
        $this->assertFalse( app( ProcurementService::class )->get()->contains( 'id', $legacy->id ) );
        $this->post( '/dashboard/purchase-trials/' . $legacy->id, ['action' => 'restore', 'acknowledge' => 1] )->assertRedirect();
        $this->assertNull( $legacy->fresh()->trial_archived_at );
    }

    public function test_real_receipts_and_staff_cannot_use_trial_archive(): void
    {
        $po = $this->order();
        $this->countAndApprove( $po, 1 )->assertRedirect();
        $receipt = Procurement::where( 'purchase_order_id', $po->id )->firstOrFail();
        $this->post( '/dashboard/purchase-trials/' . $receipt->id, ['action' => 'archive', 'acknowledge' => 1] )->assertUnprocessable();
        $this->assertNull( $receipt->fresh()->trial_archived_at );
        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();
        $this->actingAs( $this->attemptGetAnyUserFromRole( 'nexopos.store.cashier' ), 'web' );
        $this->get( '/dashboard/purchase-trials' )->assertForbidden();
    }

    public function test_stocked_receipt_paperwork_can_change_without_stock_or_status_changes(): void
    {
        $po = $this->order();
        $this->countAndApprove( $po, 1 )->assertRedirect();
        $receipt = Procurement::where( 'purchase_order_id', $po->id )->firstOrFail();
        $before = $this->row->fresh()->quantity;
        $histories = ProductHistory::count();
        $transactions = TransactionHistory::count();
        $page = $this->get( '/dashboard/purchase-paperwork/' . $receipt->id )->assertOk();
        preg_match( '/name="version" value="([a-f0-9]{64})"/', $page->getContent(), $matches );
        $data = ['version' => $matches[1], 'delivery_note_date' => '2026-09-26', 'billing_note_number' => 'BILL-LATER', 'billing_note_date' => '2026-09-27', 'quantity' => 99999, 'delivery_status' => 'pending', 'branch_id' => $this->other->id];
        $this->post( '/dashboard/purchase-paperwork/' . $receipt->id, $data )->assertRedirect();
        $this->assertEquals( 'BILL-LATER', $receipt->fresh()->billing_note_number );
        $this->assertEquals( 'stocked', $receipt->fresh()->delivery_status );
        $this->assertEquals( $this->branch->id, $receipt->fresh()->branch_id );
        $this->assertEquals( $before, $this->row->fresh()->quantity );
        $this->assertEquals( $histories, ProductHistory::count() );
        $this->assertEquals( $transactions, TransactionHistory::count() );
        $this->postJson( '/dashboard/purchase-paperwork/' . $receipt->id, $data )->assertUnprocessable();
    }

    private function order(): PurchaseOrder
    {
        $response = $this->postJson( '/api/purchase-orders', $this->payload )->assertOk();
        Auth::shouldUse( 'web' );

        return PurchaseOrder::findOrFail( $response->json( 'id' ) );
    }

    private function countAndApprove( PurchaseOrder $po, float $qty, array $extra = [] ): TestResponse
    {
        Auth::shouldUse( 'web' );

        $response = $this->postJson( '/dashboard/purchase-orders/' . $po->id . '/receive', array_merge( ['version' => $po->fresh()->version, 'quantities' => [0 => $qty], 'received_at' => '2026-09-27', 'invoice_date' => '2026-09-27', 'invoice_reference' => 'INV-TEST', 'delivery_note_number' => 'DN-TEST', 'billing_note_number' => 'BN-TEST', 'payment_status' => 'unpaid', 'acknowledge' => 1], $extra ) );
        if ( $response->getStatusCode() === 302 ) {
            $receipt = Procurement::where( 'purchase_order_id', $po->id )->latest( 'id' )->firstOrFail();
            $this->approveReceipt( $receipt )->assertRedirect();
        }

        return $response;
    }

    private function approveReceipt( Procurement $receipt, array $extra = [] ): TestResponse
    {
        $page = $this->get( '/dashboard/purchase-paperwork/' . $receipt->id )->assertOk();
        preg_match( '/name="version" value="([a-f0-9]{64})"/', $page->getContent(), $matches );

        return $this->postJson( '/dashboard/billing-approval/' . $receipt->id, array_merge( ['version' => $matches[1], 'acknowledge' => 1], $extra ) );
    }

    public function test_count_waits_for_billing_and_confirmation_posts_once(): void
    {
        $po = $this->order();
        $history = ProductHistory::count();
        $accounting = TransactionHistory::count();
        $this->postJson( '/dashboard/purchase-orders/' . $po->id . '/receive', ['version' => $po->version, 'quantities' => [0 => 4], 'received_at' => '2026-09-27', 'invoice_date' => '2026-09-27', 'acknowledge' => 1] )->assertRedirect();
        $receipt = Procurement::where( 'purchase_order_id', $po->id )->firstOrFail();
        $this->assertEquals( 'pending', $receipt->billing_approval_status );
        $this->assertEquals( 10, $this->row->fresh()->quantity );
        $this->assertEquals( $accounting, TransactionHistory::count() );
        $this->assertEquals( $history, ProductHistory::count() );
        $this->assertEquals( 0, app( ProviderService::class )->receiptSummary( $this->provider->id, $this->branch->id )['amount_due'] );
        $this->get( '/dashboard/billing-approval?branch_id=' . $this->branch->id )->assertOk()->assertSee( $receipt->name );
        $this->approveReceipt( $receipt, ['acknowledge' => 0] )->assertUnprocessable();
        $this->approveReceipt( $receipt, ['version' => str_repeat( 'b', 64 )] )->assertUnprocessable();
        Gate::define( 'nexopos.update.procurements', fn() => false );
        $this->postJson( '/dashboard/billing-approval/' . $receipt->id, ['acknowledge' => 1, 'version' => str_repeat( 'a', 64 )] )->assertForbidden();
        Gate::define( 'nexopos.update.procurements', fn() => true );
        $this->postJson( '/dashboard/payment-vouchers', ['request_key' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'provider_id' => $this->provider->id, 'receipts' => [$receipt->id], 'mode' => 'payment', 'paid_at' => '2026-09-27', 'method' => 'cash'] )->assertUnprocessable();
        $this->approveReceipt( $receipt )->assertRedirect();
        $this->assertEquals( 14, $this->row->fresh()->quantity );
        $this->assertEquals( 'approved', $receipt->fresh()->billing_approval_status );
        $this->assertEquals( '2026-09-27', $receipt->fresh()->due_date );
        $this->assertEquals( auth()->id(), $receipt->fresh()->billing_approved_by );
        $this->assertEquals( $accounting + 2, TransactionHistory::count() );
        $this->assertEquals( $history + 1, ProductHistory::count() );
        $this->approveReceipt( $receipt )->assertRedirect();
        $this->assertEquals( 14, $this->row->fresh()->quantity );
        $this->assertEquals( $accounting + 2, TransactionHistory::count() );
        $this->assertEquals( 80, $this->provider->fresh()->amount_due );
    }

    public function test_billing_denies_wrong_branch_and_rolls_back_when_accounting_rule_is_missing(): void
    {
        $po = $this->order();
        $this->postJson( '/dashboard/purchase-orders/' . $po->id . '/receive', ['version' => $po->version, 'quantities' => [0 => 2], 'received_at' => '2026-09-27', 'invoice_date' => '2026-09-27', 'acknowledge' => 1] )->assertRedirect();
        $receipt = Procurement::where( 'purchase_order_id', $po->id )->firstOrFail();
        $admin = auth()->user();
        $staff = $this->attemptGetAnyUserFromRole( 'nexopos.store.cashier' );
        $staff->branch_id = $this->other->id;
        $staff->save();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs( $staff, 'web' );
        Gate::define( 'nexopos.read.procurements', fn() => true );
        Gate::define( 'nexopos.update.procurements', fn() => true );
        $this->getJson( '/dashboard/billing-approval?branch_id=' . $this->branch->id )->assertForbidden();
        $this->postJson( '/dashboard/billing-approval/' . $receipt->id, ['acknowledge' => 1, 'version' => str_repeat( 'a', 64 )] )->assertForbidden();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs( $admin, 'web' );
        DB::table( 'nexopos_transactions_actions_rules' )->where( 'on', 'procurement_unpaid' )->delete();
        $history = ProductHistory::count();
        $accounting = TransactionHistory::count();
        $this->approveReceipt( $receipt )->assertUnprocessable();
        $this->assertEquals( 10, $this->row->fresh()->quantity );
        $this->assertEquals( 'pending', $receipt->fresh()->billing_approval_status );
        $this->assertEquals( $history, ProductHistory::count() );
        $this->assertEquals( $accounting, TransactionHistory::count() );
    }

    public function test_order_is_separate_and_does_not_stock_or_post_accounting_even_when_payload_requests_it(): void
    {
        $before = Procurement::count();
        $transactions = TransactionHistory::count();
        $po = $this->order();
        app( ProcurementService::class )->stockAwaitingProcurements();
        $this->assertEquals( 10, $this->row->fresh()->quantity );
        $this->assertEquals( $before, Procurement::count() );
        $this->assertEquals( $transactions, TransactionHistory::count() );
        $this->assertEquals( 'pending', $po->status );
        $form = $this->getJson( '/api/purchase-orders/form' )->assertOk();
        $names = array_column( $form->json( 'tabs.general.fields' ), 'name' );
        $this->assertContains( 'provider_id', $names );
        $this->assertNotContains( 'delivery_status', $names );
        $this->assertNotContains( 'invoice_reference', $names );
        $this->assertNotContains( 'billing_note_number', $names );
        Auth::shouldUse( 'web' );
        $this->get( '/dashboard/procurements/create' )->assertOk()->assertSee( 'ขั้นตอนที่ 1' );
    }

    public function test_partial_receipts_increase_only_target_branch_and_preserve_order_quantity(): void
    {
        $other = $this->row->replicate();
        $other->branch_id = $this->other->id;
        $other->quantity = 99;
        $other->save();
        $po = $this->order();
        $this->withSession( ['selected_branch_id' => $this->other->id] );
        $this->countAndApprove( $po, 4 )->assertRedirect();
        $this->assertEquals( 14, $this->row->fresh()->quantity );
        $this->assertEquals( 99, $other->fresh()->quantity );
        $this->assertEquals( 'partial', $po->fresh()->status );
        $this->countAndApprove( $po, 6 )->assertRedirect();
        $this->assertEquals( 20, $this->row->fresh()->quantity );
        $this->assertEquals( 'received', $po->fresh()->status );
        $this->assertEquals( 10, $po->fresh()->lines[0]['quantity'] );
        $this->assertCount( 2, $po->fresh()->receipts );
        $this->assertEquals( 10, ProductHistory::where( 'product_id', $this->row->product_id )->where( 'branch_id', $this->branch->id )->sum( 'quantity' ) );
        $receipt = Procurement::where( 'purchase_order_id', $po->id )->firstOrFail();
        $this->assertEquals( 'DN-TEST', $receipt->delivery_note_number );
        $this->assertEquals( 'stocked', $receipt->delivery_status );
        $this->countAndApprove( $po, 1 )->assertStatus( 422 );
        $this->assertEquals( 20, $this->row->fresh()->quantity );
        $this->get( '/dashboard/purchase-orders/' . $po->id )->assertOk()->assertSee( 'ตรวจนับครบแล้ว' );
    }

    public function test_over_receipt_duplicate_version_and_zero_are_rejected(): void
    {
        $po = $this->order();
        $this->countAndApprove( $po, 11 )->assertStatus( 422 );
        $this->countAndApprove( $po, 0 )->assertStatus( 422 );
        $this->countAndApprove( $po, 4 )->assertRedirect();
        $this->countAndApprove( $po, 4, ['version' => 1] )->assertStatus( 422 );
        $this->assertEquals( 14, $this->row->fresh()->quantity );
        $this->assertEquals( 1, Procurement::where( 'purchase_order_id', $po->id )->count() );
    }

    public function test_edit_before_receiving_and_lock_after_partial_receipt(): void
    {
        $po = $this->order();
        $data = $this->payload;
        $data['version'] = 1;
        $data['products'][0]['quantity'] = 12;
        $data['products'][0]['total_purchase_price'] = 240;
        $this->postJson( '/api/purchase-orders/' . $po->id, $data )->assertOk();
        $this->assertEquals( 12, $po->fresh()->lines[0]['quantity'] );
        $this->assertEquals( 10, $this->row->fresh()->quantity );
        $this->countAndApprove( $po, 3 )->assertRedirect();
        $data['version'] = $po->fresh()->version;
        $this->postJson( '/api/purchase-orders/' . $po->id, $data )->assertStatus( 422 );
    }

    public function test_employee_cannot_read_edit_create_or_receive_foreign_branch_order(): void
    {
        $po = $this->order();
        $staff = $this->attemptGetAnyUserFromRole( 'nexopos.store.cashier' );
        $staff->branch_id = $this->other->id;
        $staff->save();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs( $staff, 'web' );
        foreach ( ['read', 'create', 'update'] as $p ) {
            Gate::define( 'nexopos.' . $p . '.procurements', fn () => true );
        }
        $this->getJson( '/dashboard/purchase-orders/' . $po->id )->assertForbidden();
        $this->countAndApprove( $po, 1 )->assertForbidden();
        $this->postJson( '/api/purchase-orders', $this->payload )->assertForbidden();
        $this->postJson( '/api/purchase-orders/' . $po->id, array_merge( $this->payload, ['version' => 1] ) )->assertForbidden();
        $this->assertEquals( 10, $this->row->fresh()->quantity );
    }

    public function test_receipt_rolls_back_if_stock_unit_is_missing(): void
    {
        $po = $this->order();
        $this->row->delete();
        $transactions = TransactionHistory::count();
        $this->countAndApprove( $po, 2 )->assertStatus( 422 );
        $this->assertEquals( 0, Procurement::where( 'purchase_order_id', $po->id )->count() );
        $this->assertEquals( $transactions, TransactionHistory::count() );
        $this->assertEquals( 'pending', $po->fresh()->status );
    }

    public function test_confirmed_receipt_cannot_be_deleted_or_edited_through_legacy_endpoints(): void
    {
        $po = $this->order();
        $this->countAndApprove( $po, 4 )->assertRedirect();
        $receipt = Procurement::where( 'purchase_order_id', $po->id )->firstOrFail();
        $line = $receipt->products->first();
        $this->deleteJson( '/api/procurements/' . $receipt->id )->assertStatus( 403 );
        $this->deleteJson( '/api/procurements/' . $receipt->id . '/products/' . $line->id )->assertStatus( 403 );
        $this->assertEquals( 14, $this->row->fresh()->quantity );
        $this->assertNotNull( $receipt->fresh() );
    }

    public function test_receiving_requires_update_permission(): void
    {
        $po = $this->order();
        Gate::define( 'nexopos.update.procurements', fn () => false );
        $this->countAndApprove( $po, 1 )->assertForbidden();
        $this->assertEquals( 10, $this->row->fresh()->quantity );
    }

    public function test_receipt_history_is_private_to_its_branch(): void
    {
        $po = $this->order();
        $this->countAndApprove( $po, 1 )->assertRedirect();
        $receipt = Procurement::where( 'purchase_order_id', $po->id )->firstOrFail();
        $staff = $this->attemptGetAnyUserFromRole( 'nexopos.store.cashier' );
        $staff->branch_id = $this->other->id;
        $staff->save();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs( $staff, 'web' );
        foreach ( ['read', 'create', 'update'] as $p ) {
            Gate::define( 'nexopos.' . $p . '.procurements', fn () => true );
        }
        $this->getJson( '/dashboard/procurements/edit/' . $receipt->id . '/invoice' )->assertForbidden();
        $this->getJson( '/api/procurements/' . $receipt->id )->assertForbidden();
        $this->postJson( '/api/procurements/' . $receipt->id . '/set-as-paid' )->assertForbidden();
        $list = $this->getJson( '/api/procurements' )->assertOk()->json();
        $this->assertNotContains( $receipt->id, array_column( $list, 'id' ) );
    }

    public function test_receiving_packs_adds_to_base_unit_and_logs_same_unit(): void
    {
        $group = new UnitGroup;
        $group->name = 'PO conversion';
        $group->author_id = auth()->id();
        $group->save();
        $base = new Unit;
        $base->name = 'ชิ้น';
        $base->identifier = 'po-base';
        $base->value = 1;
        $base->base_unit = true;
        $base->group_id = $group->id;
        $base->author_id = auth()->id();
        $base->save();
        $pack = new Unit;
        $pack->name = 'แพ็ค';
        $pack->identifier = 'po-pack';
        $pack->value = 12;
        $pack->base_unit = false;
        $pack->group_id = $group->id;
        $pack->author_id = auth()->id();
        $pack->save();
        $product = $this->row->product;
        $product->unit_group = $group->id;
        $product->stock_on_base_unit = true;
        $product->save();
        $this->row->unit_id = $base->id;
        $this->row->save();
        $this->payload['products'][0]['unit_id'] = $pack->id;
        $po = $this->order();
        $this->countAndApprove( $po, 2 )->assertRedirect();
        $this->assertEquals( 34, $this->row->fresh()->quantity );
        $history = ProductHistory::where( 'product_id', $product->id )->firstOrFail();
        $this->assertEquals( $base->id, $history->unit_id );
        $this->assertEquals( 24, $history->quantity );
        $this->assertEquals( 34, $history->after_quantity );
        $packRow = $this->row->replicate();
        $packRow->unit_id = $pack->id;
        $packRow->quantity = 0;
        $packRow->barcode = 'PACK-STOCK-TEST';
        $packRow->save();
        $this->postJson( '/api/purchase-orders/search', ['search' => 'PACK-STOCK-TEST', 'provider_id' => $this->provider->id, 'branch_id' => $this->branch->id] )
            ->assertOk()->assertJsonPath( '0.unit_quantities.0.current_stock_quantity', 34 )
            ->assertJsonPath( '0.unit_quantities.0.current_stock_unit', $base->name );

    }

    public function test_legacy_api_cannot_forge_a_purchase_order_receipt_link(): void
    {
        $po = $this->order();
        $data = $this->payload;
        $data['general']['purchase_order_id'] = $po->id;
        $this->postJson( '/api/procurements', $data )->assertStatus( 422 );
        $this->assertEquals( 0, Procurement::where( 'purchase_order_id', $po->id )->count() );
        $this->assertEquals( 10, $this->row->fresh()->quantity );
    }
}
