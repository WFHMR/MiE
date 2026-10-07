<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\CouponProduct;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Services\CouponPricingService;
use App\Services\CustomerService;
use App\Services\OrdersService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class CouponPromotionTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private array $lines;

    private Branch $branch;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs( $this->attemptGetAnyUserFromRole() );
        $this->branch = Branch::create( ['code' => 'PROMO-TEST', 'name' => 'สาขาโปรทดสอบ', 'is_active' => true, 'author_id' => auth()->id()] );
        session( ['selected_branch_id' => $this->branch->id] );
        $this->customer = Customer::firstOrFail()->id;
        $this->lines = [];
        foreach ( [60, 40] as $n => $price ) {
            $p = Product::factory()->create( ['name' => 'โปรทดสอบสินค้า ' . $n, 'stock_management' => 'disabled', 'type' => 'materialized', 'tax_type' => 'disabled'] );
            $u = Unit::firstOrFail();
            $row = new ProductUnitQuantity;
            $row->product_id = $p->id;
            $row->unit_id = $u->id;
            $row->branch_id = $this->branch->id;
            $row->sale_price = $price;
            $row->wholesale_price = $price;
            $row->quantity = 100;
            $row->save();
            $this->lines[] = ['product_id' => $p->id, 'unit_id' => $u->id, 'unit_quantity_id' => $row->id, 'quantity' => 1, 'unit_price' => $price, 'total_price' => $price];
        }
    }

    private function coupon( string $type = 'bundle_price', float $price = 80 ): Coupon
    {
        $payload = ['name' => 'โปรทดสอบ', 'general' => ['code' => 'QA-PROMO-' . uniqid(), 'type' => $type, 'discount_value' => $price, 'valid_until' => null, 'valid_hours_start' => null, 'valid_hours_end' => null, 'minimum_cart_value' => 0, 'maximum_cart_value' => 0, 'limit_usage' => 0, 'bundle_items' => json_encode( $type === 'bundle_price' ? array_map( fn( $p ) => array_intersect_key( $p, array_flip( ['product_id', 'unit_id', 'quantity'] ) ), $this->lines ) : [] )], 'selected_products' => ['products' => []], 'selected_categories' => ['categories' => []], 'selected_groups' => ['groups' => []]];
        $response = $this->postJson( '/api/crud/ns.coupons', $payload )->assertSuccessful()->assertJsonPath( 'status', 'success' );

        return Coupon::findOrFail( $response->json( 'data.entry.id' ) );
    }

    private function sale( Coupon $coupon, float $value, float $payment ): array
    {
        return ['customer_id' => $this->customer, 'branch_id' => $this->branch->id, 'type' => ['identifier' => 'takeaway'], 'discount_type' => 'flat', 'discount' => 0, 'discount_percentage' => 0, 'shipping' => 0, 'subtotal' => 100, 'tax_type' => 'disabled', 'taxes' => [], 'products' => $this->lines, 'coupons' => [['coupon_id' => $coupon->id, 'value' => $value]], 'payments' => [['identifier' => 'cash-payment', 'value' => $payment]]];
    }

    public function test_bundle_definition_survives_create_edit_and_load(): void
    {
        $coupon = $this->coupon();
        $this->assertCount( 2, $coupon->bundle_rules );
        $this->assertEquals( 1, $coupon->bundle_rules[0]['quantity'] );
        $this->getJson( '/api/crud/ns.coupons/form-config/' . $coupon->id )->assertSuccessful();
        $this->postJson( '/api/customers/coupons/' . $coupon->code, ['customer_id' => $this->customer] )->assertSuccessful()->assertJsonCount( 2, 'bundle_rules' );
    }

    public function test_bundle_is_saved_with_real_discount_and_actual_order_total(): void
    {
        $coupon = $this->coupon();
        $order = app( OrdersService::class )->create( $this->sale( $coupon, 20, 80 ) )['data']['order'];
        $this->assertEquals( 80, $order->total );
        $this->assertEquals( 20, $order->total_coupons );
        $this->assertEquals( 20, $order->coupons()->firstOrFail()->value );
        $this->assertSame( Order::PAYMENT_PAID, $order->payment_status );
    }

    public function test_cart_percentage_and_flat_discount(): void
    {
        foreach ( [['percentage_discount', 10, 10], ['flat_discount', 25, 25]] as [$type,$rate,$value] ) {
            $coupon = $this->coupon( $type, $rate );
            $order = app( OrdersService::class )->create( $this->sale( $coupon, $value, 100 - $value ) )['data']['order'];
            $this->assertEquals( 100 - $value, $order->total );
            $this->assertEquals( $value, $order->coupons()->firstOrFail()->value );
        }
    }

    public function test_multiple_sets_leave_extra_items_at_regular_price(): void
    {
        $coupon = $this->coupon();
        $rows = $this->lines;
        $rows[0]['quantity'] = 3;
        $rows[0]['total_price'] = 180;
        $rows[1]['quantity'] = 2;
        $rows[1]['total_price'] = 80;
        $this->assertEquals( 40, app( CouponPricingService::class )->value( $coupon, $rows, 260 ) );
    }

    public function test_incomplete_bundle_rejected_without_order(): void
    {
        $coupon = $this->coupon();
        $fields = $this->sale( $coupon, 20, 40 );
        array_pop( $fields['products'] );
        $count = Order::count();
        try {
            app( OrdersService::class )->create( $fields );
            $this->fail( 'Incomplete set accepted' );
        } catch ( NotAllowedException $e ) {
            $this->assertStringContainsString( 'ครบชุด', $e->getMessage() );
        }
        $this->assertSame( $count, Order::count() );
    }

    public function test_wrong_unit_is_not_counted_as_a_bundle(): void
    {
        $coupon = $this->coupon();
        $rows = $this->lines;
        $rows[0]['unit_id'] = 999999;
        $this->expectException( NotAllowedException::class );
        app( CouponPricingService::class )->value( $coupon, $rows, 100 );
    }

    public function test_tampered_value_is_rejected_without_order(): void
    {
        $coupon = $this->coupon();
        $count = Order::count();
        try {
            app( OrdersService::class )->create( $this->sale( $coupon, 99, 1 ) );
            $this->fail( 'Tampered discount accepted' );
        } catch ( NotAllowedException $e ) {
            $this->assertStringContainsString( 'ยอดโปรเปลี่ยน', $e->getMessage() );
        }
        $this->assertSame( $count, Order::count() );
    }

    public function test_product_condition_and_minimum_are_checked_on_server(): void
    {
        $coupon = $this->coupon( 'flat_discount', 10 );
        $relation = new CouponProduct;
        $relation->coupon_id = $coupon->id;
        $relation->product_id = $this->lines[1]['product_id'];
        $relation->save();
        $this->expectException( NotAllowedException::class );
        app( CouponPricingService::class )->value( $coupon, [$this->lines[0]], 60 );
    }

    public function test_daily_time_window_accepts_inside_and_rejects_outside(): void
    {
        $coupon = $this->coupon( 'flat_discount', 10 );
        $coupon->valid_hours_start = '2020-01-01 09:00:00';
        $coupon->valid_hours_end = '2020-01-01 17:00:00';
        $coupon->save();
        $this->travelTo( now()->setTime( 12, 0 ) );
        $this->assertSame( $coupon->id, app( CustomerService::class )->checkCouponExistence( ['coupon_id' => $coupon->id], ['customer_id' => $this->customer] )->id );
        $this->travelTo( now()->setTime( 20, 0 ) );
        $this->expectException( NotAllowedException::class );
        app( CustomerService::class )->checkCouponExistence( ['coupon_id' => $coupon->id], ['customer_id' => $this->customer] );
    }

    public function test_search_real_products_and_units_with_branch_barcode(): void
    {
        $id = $this->lines[0]['unit_quantity_id'];
        ProductUnitQuantity::whereKey( $id )->update( ['barcode' => 'QA-PROMO-BARCODE'] );
        $this->getJson( '/api/promotions/products?q=QA-PROMO-BARCODE' )->assertSuccessful()->assertJsonPath( '0.id', $this->lines[0]['product_id'] )->assertJsonPath( '0.units.0.unit_id', $this->lines[0]['unit_id'] );
    }

    public function test_search_denied_without_coupon_management_permission(): void
    {
        Gate::before( fn() => false );
        $this->getJson( '/api/promotions/products?q=test' )->assertForbidden();
    }

    public function test_invalid_bundle_and_percentage_are_rejected(): void
    {
        $this->postJson( '/api/crud/ns.coupons', ['name' => 'bad', 'general' => ['code' => 'BADQA', 'type' => 'percentage_discount', 'discount_value' => 101]] )->assertStatus( 422 );
        $this->postJson( '/api/crud/ns.coupons', ['name' => 'bad', 'general' => ['code' => 'BADQA2', 'type' => 'bundle_price', 'discount_value' => 100, 'bundle_items' => '[]']] )->assertStatus( 422 );
    }

    public function test_coupon_usage_cannot_bypass_limit_when_job_has_not_run(): void
    {
        $coupon = $this->coupon( 'flat_discount', 10 );
        $coupon->limit_usage = 1;
        $coupon->save();
        Queue::fake();
        app( OrdersService::class )->create( $this->sale( $coupon, 10, 90 ) );
        $this->expectException( NotAllowedException::class );
        app( OrdersService::class )->create( $this->sale( $coupon, 10, 90 ) );
    }

    public function test_coupon_cannot_stack_with_another_promotion_or_cart_discount(): void
    {
        $coupon = $this->coupon();
        $fields = $this->sale( $coupon, 20, 70 );
        $fields['discount'] = 10;
        $this->expectException( NotAllowedException::class );
        app( OrdersService::class )->create( $fields );
    }

    public function test_minimum_and_expiry_are_enforced(): void
    {
        $coupon = $this->coupon( 'flat_discount', 10 );
        $coupon->minimum_cart_value = 101;
        $coupon->save();
        try {
            app( OrdersService::class )->create( $this->sale( $coupon, 10, 90 ) );
            $this->fail( 'Minimum ignored' );
        } catch ( NotAllowedException $e ) {
            $this->assertStringContainsString( 'ช่วง', $e->getMessage() );
        }
        $coupon->minimum_cart_value = 0;
        $coupon->valid_until = now()->subDay();
        $coupon->save();
        $this->expectException( NotAllowedException::class );
        app( OrdersService::class )->create( $this->sale( $coupon, 10, 90 ) );
    }

    public function test_bundle_uses_new_branch_prices_without_changing_inventory(): void
    {
        $coupon = $this->coupon();
        $branch = Branch::create( ['code' => 'PROMO-SECOND', 'name' => 'โปรสาขาสอง', 'is_active' => true, 'author_id' => auth()->id()] );
        foreach ( $this->lines as &$line ) {
            $original = ProductUnitQuantity::findOrFail( $line['unit_quantity_id'] );
            $new = new ProductUnitQuantity;
            $new->product_id = $original->product_id;
            $new->unit_id = $original->unit_id;
            $new->branch_id = $branch->id;
            $new->sale_price = $line['unit_price'] + 10;
            $new->wholesale_price = $line['unit_price'] + 10;
            $new->quantity = 100;
            $new->save();
            $line['unit_quantity_id'] = $new->id;
            $line['unit_price'] += 10;
            $line['total_price'] = $line['unit_price'];
        }unset( $line );
        session( ['selected_branch_id' => $branch->id] );
        $fields = $this->sale( $coupon, 40, 80 );
        $fields['branch_id'] = $branch->id;
        $order = app( OrdersService::class )->create( $fields )['data']['order'];
        $this->assertEquals( 80, $order->total, json_encode( $order->products()->get( ['unit_price', 'total_price', 'quantity'] )->toArray() ) );
        $this->assertEquals( 40, $order->total_coupons );
    }

    public function test_bundle_refund_cannot_exceed_actual_payment(): void
    {
        $coupon = $this->coupon();
        $service = app( OrdersService::class );
        $order = $service->create( $this->sale( $coupon, 20, 80 ) )['data']['order'];
        $items = $order->products()->get()->map( fn( $p ) => ['id' => $p->id, 'quantity' => 1, 'unit_price' => $p->unit_price, 'condition' => 'unspoiled', 'description' => 'QA'] )->all();
        try {
            $service->refundOrder( $order, ['payment' => ['identifier' => 'cash-payment'], 'products' => $items, 'refund_shipping' => false] );
            $this->fail( 'Refund exceeded payment' );
        } catch ( NotAllowedException $e ) {
            $this->assertNotEmpty( $e->getMessage() );
        }
        $items[0]['unit_price'] = 48;
        $items[1]['unit_price'] = 32;
        $service->refundOrder( $order, ['payment' => ['identifier' => 'cash-payment'], 'products' => $items, 'refund_shipping' => false] );
        $this->assertEquals( 80, $order->refunds()->sum( 'total' ) );
        $service->refreshOrder( $order->fresh() );
        $this->assertEquals( 0, $order->fresh()->total );
    }

    public function test_partial_then_full_bundle_refund_preserves_net_revenue(): void
    {
        $coupon = $this->coupon();
        $service = app( OrdersService::class );
        $order = $service->create( $this->sale( $coupon, 20, 80 ) )['data']['order'];
        $products = $order->products()->get();
        foreach ( [48, 32] as $index => $amount ) {
            $service->refundOrder( $order->fresh(), ['payment' => ['identifier' => 'cash-payment'], 'products' => [['id' => $products[$index]->id, 'quantity' => 1, 'unit_price' => $amount, 'condition' => 'unspoiled', 'description' => 'QA partial']], 'refund_shipping' => false] );
            $service->refreshOrder( $order->fresh() );
            $this->assertEquals( $index === 0 ? 32 : 0, $order->fresh()->total );
        }
        $this->assertEquals( 80, $order->refunds()->sum( 'total' ) );
    }

    public function test_coupon_tax_checkout_refresh_and_refund(): void
    {
        $group = TaxGroup::with( 'taxes' )->whereHas( 'taxes', fn( $q ) => $q->where( 'rate', 7 ) )->firstOrFail();
        foreach ( ['flat_vat', 'variable_vat'] as $mode ) {
            ns()->option->set( 'ns_pos_vat', $mode );
            foreach ( ['inclusive', 'exclusive'] as $type ) {
                $coupon = $this->coupon();
                $total = $type === 'exclusive' ? 85.6 : 80;
                $tax = $type === 'exclusive' ? 5.6 : 5.23;
                $fields = $this->sale( $coupon, 20, $total );
                $fields['tax_type'] = $type;
                $fields['tax_group_id'] = $group->id;
                $fields['taxes'] = $group->taxes->map( fn( $t ) => ['tax_id' => $t->id, 'rate' => $t->rate, 'name' => $t->name] )->all();
                $service = app( OrdersService::class );
                $order = $service->create( $fields )['data']['order'];
                $this->assertEquals( $total, $order->total, "$mode $type checkout" );
                $this->assertEqualsWithDelta( $tax, $order->tax_value, 0.005 );
                $this->assertSame( Order::PAYMENT_PAID, $order->payment_status );
                $service->refreshOrder( $order->fresh() );
                $this->assertEquals( $total, $order->fresh()->total, "$mode $type refresh" );
                $items = $order->products()->get();
                $service->refundOrder( $order->fresh(), ['payment' => ['identifier' => 'cash-payment'], 'products' => [['id' => $items[0]->id, 'quantity' => 1, 'unit_price' => round( $total * .6, 2 ), 'condition' => 'unspoiled', 'description' => 'QA tax']], 'refund_shipping' => false] );
                $service->refreshOrder( $order->fresh() );
                $this->assertEquals( round( $total * .4, 2 ), $order->fresh()->total );
                $this->assertEqualsWithDelta( $type === 'exclusive' ? 2.24 : 2.09, $order->fresh()->tax_value, 0.005 );
            }
        }
    }

    public function test_coupon_exclusive_tax_cannot_be_marked_paid_without_tax_payment(): void
    {
        ns()->option->set( 'ns_pos_vat', 'flat_vat' );
        ns()->option->set( 'ns_orders_allow_partial', false );
        $coupon = $this->coupon();
        $fields = $this->sale( $coupon, 20, 80 );
        $fields['tax_type'] = 'exclusive';
        $fields['taxes'] = [['tax_id' => 1, 'name' => 'VAT', 'rate' => 7]];
        $this->expectException( NotAllowedException::class );
        app( OrdersService::class )->create( $fields );
    }
}
