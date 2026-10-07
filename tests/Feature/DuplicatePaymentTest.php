<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPayment;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Order 260921-020: three items at 37, 40 and 12 — 89 baht. The customer handed over 100. The receipt
 * printed 111 baht of change.
 *
 * The subtraction was not wrong. Two cash payments of 100 had been recorded against the order, both
 * stamped 13:16:34, so the till believed 200 had been handed over and 200 - 89 is 111. The same thing had
 * happened once before, on 260910-001: an 82 baht sale with two payments of 82 and 82 baht handed back.
 * Two occurrences out of 107 orders, 100 and 82 baht out of the drawer.
 *
 * Three things allowed it, and all three are addressed:
 *
 * 1. `ns-numpad-screen` bound Enter with `.prevent` only. That stops the browser's default but not the
 *    bubble, so the keystroke also reached the global `enter` shortcut the payment screen registers, and
 *    one press ran the same handler twice.
 * 2. `sample-payment.vue` cleared the screen *after* recording the payment, so anything that re-entered
 *    the handler in the same turn read the same amount again.
 * 3. Nothing anywhere refused a payment identical to the one just taken.
 *
 * The server is not the place to decide this. By the time a payload arrives, two payments of 100 are
 * indistinguishable from a customer counting out two notes — which is why `__saveOrderPayments()` still
 * records exactly what it is given.
 */
class DuplicatePaymentTest extends TestCase
{
    use WithAuthentication;

    private function source( string $path ): string
    {
        return (string) file_get_contents( base_path( $path ) );
    }

    public function test_enter_on_the_amount_field_does_not_also_reach_the_global_shortcut(): void
    {
        $source = $this->source( 'resources/ts/components/ns-numpad-screen.vue' );

        $this->assertStringContainsString(
            '@keydown.enter.prevent.stop=',
            $source,
            'Enter bubbles out of the amount field again, so the payment screen\'s global enter shortcut'
                . ' fires as well and one press runs the handler twice.'
        );
    }

    public function test_the_amount_is_cleared_before_the_payment_is_recorded(): void
    {
        $source = $this->source( 'resources/ts/pages/dashboard/pos/payments/sample-payment.vue' );

        /**
         * Scoped to inputValue(). Searching the whole file found makeFullPayment()'s addPayment call
         * first, which sits before the clear and made this pass for the wrong reason.
         */
        $scope = substr( $source, (int) strpos( $source, 'inputValue( key ) {' ) );

        $clearPosition = strpos( $scope, "this.backValue      =   '';" );
        $addPosition = strpos( $scope, 'POS.addPayment({' );

        $this->assertNotFalse( $clearPosition );
        $this->assertNotFalse( $addPosition );

        $this->assertLessThan(
            $addPosition,
            $clearPosition,
            'The screen is cleared after the payment is added again. A second call in the same turn reads'
                . ' the same amount and records it twice.'
        );
    }

    public function test_an_identical_payment_repeated_faster_than_hands_can_work_is_refused(): void
    {
        $source = $this->source( 'resources/ts/pos-init.ts' );

        $this->assertStringContainsString(
            'DUPLICATE_PAYMENT_WINDOW_MS',
            $source,
            'Nothing refuses a payment identical to the one just added, so any double fire still doubles'
                . ' the money the customer is believed to have handed over.'
        );

        $this->assertStringContainsString(
            '_lastPaymentSignature',
            $source,
            'The duplicate guard has nothing to compare against.'
        );
    }

    /**
     * The window has to be short enough that counting out two notes of the same value still works. A
     * cashier taking two 500 notes for a 900 baht sale is doing their job, and a guard that blocked it
     * would be trading one broken workflow for another.
     */
    public function test_the_guard_does_not_outlaw_counting_out_two_identical_notes(): void
    {
        $source = $this->source( 'resources/ts/pos-init.ts' );

        preg_match( '/DUPLICATE_PAYMENT_WINDOW_MS\s*=\s*(\d+)/', $source, $matches );

        $this->assertNotEmpty( $matches, 'The duplicate window is no longer a readable constant.' );

        $window = (int) $matches[ 1 ];

        $this->assertGreaterThan( 0, $window );
        $this->assertLessThanOrEqual(
            3000,
            $window,
            'The window is long enough to block a cashier legitimately entering the same amount twice.'
        );
    }

    /**
     * A new sale must start clean, or the first payment of the next customer could be refused for
     * resembling the last one.
     */
    public function test_the_fingerprint_is_cleared_between_sales(): void
    {
        $source = $this->source( 'resources/ts/pos-init.ts' );

        $resetPosition = strpos( $source, 'async reset()' );
        $clearPosition = strpos( $source, "this._lastPaymentSignature = '';" );

        $this->assertNotFalse( $resetPosition );
        $this->assertNotFalse( $clearPosition );
        $this->assertGreaterThan(
            $resetPosition,
            $clearPosition,
            'The payment fingerprint is not cleared when the cart resets.'
        );
    }

    /**
     * The misleading comment that claimed previous payments were deleted is gone. Believing it would lead
     * someone to assume a resubmitted order replaces its payments rather than adding to them.
     */
    public function test_the_payment_writer_no_longer_claims_to_delete_previous_payments(): void
    {
        $source = $this->source( 'app/Services/OrdersService.php' );

        $this->assertStringNotContainsString(
            'we first need to delete previous payments',
            $source,
            'The comment claiming payments are deleted first is back, and it is not true.'
        );
    }

    /**
     * And the arithmetic, stated plainly so a future change to it fails here: change is what was handed
     * over minus what was owed. Nothing about this was broken — it is the input that was doubled — and it
     * is worth pinning so that a "fix" aimed at the symptom cannot quietly alter it.
     */
    public function test_change_is_tendered_minus_total(): void
    {
        $this->attemptAuthenticate();

        $order = Order::where( 'payment_status', Order::PAYMENT_PAID )
            ->where( 'change', '>', 0 )
            ->first();

        if ( ! $order instanceof Order ) {
            $this->markTestSkipped( 'No paid order with change to check.' );
        }

        $this->assertEqualsWithDelta(
            (float) $order->tendered - (float) $order->total,
            (float) $order->change,
            0.01,
            'Change no longer equals what was handed over minus what was owed.'
        );

        $paid = OrderPayment::where( 'order_id', $order->id )->sum( 'value' );

        $this->assertEqualsWithDelta(
            (float) $paid,
            (float) $order->tendered,
            0.01,
            'The tendered figure disagrees with the payments recorded against the order.'
        );
    }
}
