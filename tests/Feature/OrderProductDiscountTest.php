<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithCustomerTest;

/**
 * Covers per-line (product level) discounts submitted from the POS.
 *
 * The frontend contract lives in resources/ts/pos-init.ts (computeDiscount) and
 * resources/ts/pages/dashboard/pos/ns-pos-cart.vue: "discount" on a product is
 * the amount for the whole line, already multiplied by the quantity. The cart
 * displays that value, so the stored order must agree with it.
 *
 * Flat discounts are deliberately rejected for products by
 * OrdersService::__checkDiscountValidity(), and the POS hides the "Flat" button
 * for product discounts through the disable_flat flag. That restriction is
 * asserted here so the two sides cannot drift apart unnoticed.
 */
class OrderProductDiscountTest extends TestCase
{
    use WithAuthentication, WithCustomerTest;

    private const QUANTITY = 4;

    /**
     * @return array{response: array, line: array, grossLineTotal: float, expectedLineTotal: float}
     */
    private function postOrderWithProductDiscount( array $productOverrides, bool $expectSuccess = true ): array
    {
        $product = Product::notGrouped()
            ->notInGroup()
            ->with( 'unit_quantities' )
            ->whereHas( 'unit_quantities' )
            ->first();

        $this->assertNotNull( $product, 'A product is required to run this test.' );

        $unitQuantity = $product->unit_quantities->first();
        $customer = Customer::first() ?: $this->attemptCreateCustomer();

        $line = array_merge( [
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => self::QUANTITY,
            'unit_price' => $unitQuantity->sale_price,
            'unit_quantity_id' => $unitQuantity->id,
            'unit_id' => $unitQuantity->unit_id,
            'tax_type' => 'disabled',
            'tax_group_id' => 0,
        ], $productOverrides );

        $grossLineTotal = $line['unit_price'] * $line['quantity'];
        $expectedLineTotal = $grossLineTotal - $line['discount'];

        $response = $this->withSession( $this->app['session']->all() )
            ->json( 'POST', 'api/orders', [
                'customer_id' => $customer->id,
                'type' => [ 'identifier' => 'takeaway' ],
                'addresses' => [],
                'author_id' => User::first()->id,
                'shipping' => 0,
                'subtotal' => $expectedLineTotal,
                'products' => [ $line ],
                'payments' => [
                    [
                        'identifier' => 'cash-payment',
                        'value' => $expectedLineTotal,
                    ],
                ],
            ] );

        if ( $expectSuccess ) {
            $response->assertStatus( 200 );
        }

        return [
            'response' => $response->json(),
            'status' => $response->status(),
            'line' => $line,
            'grossLineTotal' => $grossLineTotal,
            'expectedLineTotal' => $expectedLineTotal,
        ];
    }

    public function test_percentage_product_discount_reduces_the_line_total(): void
    {
        $this->attemptAuthenticate();

        $percentage = 10;

        $product = Product::notGrouped()
            ->notInGroup()
            ->with( 'unit_quantities' )
            ->whereHas( 'unit_quantities' )
            ->first();

        $unitPrice = $product->unit_quantities->first()->sale_price;
        $lineDiscount = ( $unitPrice * self::QUANTITY * $percentage ) / 100;

        $result = $this->postOrderWithProductDiscount( [
            'discount_type' => 'percentage',
            'discount_percentage' => $percentage,
            'discount' => $lineDiscount,
        ] );

        $savedProduct = $result['response']['data']['order']['products'][0];

        $this->assertEquals(
            $result['expectedLineTotal'],
            $savedProduct['total_price'],
            'total_price must equal (unit_price * quantity) minus the percentage discount for the line.'
        );
    }

    public function test_percentage_product_discount_is_stored_for_the_whole_line(): void
    {
        $this->attemptAuthenticate();

        $percentage = 10;

        $product = Product::notGrouped()
            ->notInGroup()
            ->with( 'unit_quantities' )
            ->whereHas( 'unit_quantities' )
            ->first();

        $unitPrice = $product->unit_quantities->first()->sale_price;
        $lineDiscount = ( $unitPrice * self::QUANTITY * $percentage ) / 100;

        $result = $this->postOrderWithProductDiscount( [
            'discount_type' => 'percentage',
            'discount_percentage' => $percentage,
            'discount' => $lineDiscount,
        ] );

        $savedProduct = $result['response']['data']['order']['products'][0];

        /**
         * Money is compared with a tolerance of half a satang. The amounts travel
         * through JSON as floats, so an exact comparison fails on values such as
         * 77.03999999999999 that are the same money as 77.04.
         */
        $this->assertEqualsWithDelta(
            $lineDiscount,
            $savedProduct['discount'],
            0.005,
            sprintf(
                'The stored discount must cover the whole line (%s), matching what the POS cart displays, not a single unit.',
                $lineDiscount
            )
        );
    }

    public function test_order_subtotal_reflects_the_product_discount(): void
    {
        $this->attemptAuthenticate();

        $percentage = 10;

        $product = Product::notGrouped()
            ->notInGroup()
            ->with( 'unit_quantities' )
            ->whereHas( 'unit_quantities' )
            ->first();

        $unitPrice = $product->unit_quantities->first()->sale_price;
        $lineDiscount = ( $unitPrice * self::QUANTITY * $percentage ) / 100;

        $result = $this->postOrderWithProductDiscount( [
            'discount_type' => 'percentage',
            'discount_percentage' => $percentage,
            'discount' => $lineDiscount,
        ] );

        $this->assertEqualsWithDelta(
            $result['expectedLineTotal'],
            $result['response']['data']['order']['subtotal'],
            0.005,
            'The order subtotal must be the sum of the discounted line totals.'
        );
    }

    /**
     * Flat product discounts are not supported. The POS hides the option, and the
     * API rejects it, so both sides must keep agreeing on that.
     */
    public function test_flat_product_discount_is_rejected(): void
    {
        $this->attemptAuthenticate();

        $result = $this->postOrderWithProductDiscount( [
            'discount_type' => 'flat',
            'discount' => 12,
            'discount_percentage' => 0,
        ], expectSuccess: false );

        $this->assertSame(
            403,
            $result['status'],
            'A flat product discount must be refused, because the POS is not allowed to submit one.'
        );

        $this->assertStringContainsString(
            'percentage',
            $result['response']['message'] ?? '',
            'The rejection should explain that product discounts must be a percentage.'
        );
    }
}
