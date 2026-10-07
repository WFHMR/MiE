<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Services\OrdersService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class QuickProductCheckoutTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    public function test_bill_tax_is_not_added_to_the_saved_subtotal_twice(): void
    {
        $this->actingAs( $this->attemptGetAnyUserFromRole() );
        $group = TaxGroup::with( 'taxes' )->whereHas( 'taxes', fn( $query ) => $query->where( 'rate', 7 ) )->firstOrFail();
        $taxes = $group->taxes->map( fn( $tax ) => ['tax_id' => $tax->id, 'name' => $tax->name, 'rate' => $tax->rate] )->toArray();

        foreach ( ['flat_vat', 'variable_vat'] as $mode ) {
            foreach ( ['net_prices', 'gross_prices'] as $prices ) {
                foreach ( ['exclusive', 'inclusive'] as $type ) {
                    ns()->option->set( 'ns_pos_vat', $mode );
                    ns()->option->set( 'ns_pos_prefered_price', $prices );
                    $subtotal = $type === 'exclusive' ? 400 : 428;
                    $fields = [
                        'customer_id' => Customer::firstOrFail()->id,
                        'branch_id' => 1,
                        'type' => ['identifier' => 'takeaway'],
                        'discount' => 0, 'discount_percentage' => 0, 'discount_type' => 'flat',
                        'coupons' => [], 'shipping' => 0,
                        'subtotal' => $subtotal, 'tax_value' => 28,
                        'tax_type' => $type, 'tax_group_id' => $group->id, 'taxes' => $taxes,
                        'products' => [[
                            'name' => 'QA quick product checkout', 'mode' => 'custom', 'product_type' => 'product',
                            'unit_id' => Unit::firstOrFail()->id, 'quantity' => 4, 'unit_price' => $subtotal / 4,
                            'discount' => 0, 'discount_percentage' => 0, 'discount_type' => 'percentage',
                            'tax_type' => $type, 'tax_group_id' => $group->id,
                        ]],
                        'payments' => [['identifier' => 'cash-payment', 'value' => 500]],
                    ];
                    $order = app( OrdersService::class )->create( $fields )['data']['order']->fresh();
                    $context = "$mode / $prices / $type";
                    $this->assertEquals( $subtotal, $order->subtotal, $context );
                    $this->assertEquals( 28, $order->tax_value, $context );
                    $this->assertEquals( 28, $order->taxes()->sum( 'tax_value' ), $context );
                    $this->assertEquals( 400, $order->total_without_tax, $context );
                    $this->assertEquals( 428, $order->total, $context );
                    $this->assertEquals( 72, $order->change, $context );
                    $this->assertEquals( 'paid', $order->payment_status, $context );
                    $this->assertEquals( 1, $order->payments()->count(), $context );
                }
            }
        }
    }
}
