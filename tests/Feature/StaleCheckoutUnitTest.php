<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Services\OrdersService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class StaleCheckoutUnitTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private ProductUnitQuantity $row;

    private array $fields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs( $this->attemptGetAnyUserFromRole() );
        $branch = Branch::create( ['code' => 'STALE-T61', 'name' => 'ทดสอบหน่วยขาย', 'is_active' => true, 'author_id' => auth()->id()] );
        session( ['selected_branch_id' => $branch->id] );
        ns()->option->set( 'ns_pos_vat', 'disabled' );
        $product = Product::factory()->create( ['name' => 'สินค้าหน่วยขายเปลี่ยน', 'stock_management' => 'enabled', 'stock_on_base_unit' => false, 'type' => 'materialized', 'tax_type' => 'disabled'] );
        $this->row = new ProductUnitQuantity;
        $this->row->product_id = $product->id;
        $this->row->unit_id = Unit::firstOrFail()->id;
        $this->row->branch_id = $branch->id;
        $this->row->quantity = 10;
        $this->row->sale_price = 72;
        $this->row->cogs = 40;
        $this->row->save();
        $this->fields = [
            'customer_id' => Customer::firstOrFail()->id, 'branch_id' => $branch->id,
            'type' => ['identifier' => 'takeaway'], 'discount_type' => 'flat', 'discount' => 0,
            'discount_percentage' => 0, 'shipping' => 0, 'subtotal' => 72,
            'tax_type' => 'disabled', 'taxes' => [], 'coupons' => [],
            'products' => [['product_id' => $product->id, 'unit_id' => $this->row->unit_id, 'unit_quantity_id' => $this->row->id, 'quantity' => 1, 'unit_price' => 72, 'total_price' => 72]],
            'payments' => [['identifier' => 'cash-payment', 'value' => 72]],
        ];
    }

    private function assertRejectedWithoutSale(): void
    {
        $counts = [Order::count(), OrderPayment::count(), ProductHistory::count()];
        try {
            app( OrdersService::class )->create( $this->fields );
            $this->fail( 'Stale or mismatched units must not be sold.' );
        } catch ( NotAllowedException $e ) {
            $this->assertStringContainsString( 'สินค้าหน่วยขายเปลี่ยน', $e->getMessage() );
            $this->assertStringContainsString( 'ค้นหาหรือสแกนเพิ่มใหม่', $e->getMessage() );
        }
        $this->assertSame( $counts, [Order::count(), OrderPayment::count(), ProductHistory::count()] );
    }

    public function test_deleted_unit_is_rejected_and_rescanned_current_unit_checks_out(): void
    {
        $replacement = $this->row->replicate();
        $this->row->delete();
        $replacement->unit_id = Unit::where( 'id', '!=', $replacement->unit_id )->firstOrFail()->id;
        $replacement->save();
        $this->assertRejectedWithoutSale();
        $this->assertEquals( 10, $replacement->fresh()->quantity );

        $this->fields['products'][0]['unit_quantity_id'] = $replacement->id;
        $this->fields['products'][0]['unit_id'] = $replacement->unit_id;
        $order = app( OrdersService::class )->create( $this->fields )['data']['order']->fresh();
        $this->assertEquals( 'paid', $order->payment_status );
        $this->assertEquals( 72, $order->total );
        $this->assertEquals( 1, $order->payments()->count() );
        $this->assertEquals( $replacement->id, $order->products()->firstOrFail()->unit_quantity_id );
        $this->assertEquals( 9, $replacement->fresh()->quantity );
    }

    public function test_existing_row_for_another_product_is_rejected(): void
    {
        $this->row->product_id = Product::factory()->create()->id;
        $this->row->save();
        $this->assertRejectedWithoutSale();
        $this->assertEquals( 10, $this->row->fresh()->quantity );
    }

    public function test_changed_unit_on_same_row_is_rejected(): void
    {
        $this->fields['products'][0]['unit_id'] = Unit::where( 'id', '!=', $this->row->unit_id )->firstOrFail()->id;
        $this->assertRejectedWithoutSale();
        $this->assertEquals( 10, $this->row->fresh()->quantity );
    }

    public function test_missing_unit_reference_is_rejected(): void
    {
        unset( $this->fields['products'][0]['unit_quantity_id'] );
        $this->assertRejectedWithoutSale();
    }

    public function test_pos_api_returns_actionable_error_without_saving_a_bill(): void
    {
        $this->row->delete();
        $count = Order::count();
        $response = $this->postJson( '/api/orders', $this->fields );
        $response->assertStatus( 403 )->assertJsonPath( 'status', 'error' );
        $this->assertStringContainsString( 'ค้นหาหรือสแกนเพิ่มใหม่', $response->json( 'message' ) );
        $this->assertStringNotContainsString( 'App\\Models', $response->json( 'message' ) );
        $this->assertEquals( $count, Order::count() );
    }

    public function test_legacy_cart_without_unit_id_can_use_valid_unit_quantity(): void
    {
        unset( $this->fields['products'][0]['unit_id'] );
        $order = app( OrdersService::class )->create( $this->fields )['data']['order']->fresh();
        $this->assertEquals( 'paid', $order->payment_status );
        $this->assertEquals( $this->row->unit_id, $order->products()->firstOrFail()->unit_id );
        $this->assertEquals( 9, $this->row->fresh()->quantity );
    }
}
