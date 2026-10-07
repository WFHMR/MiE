<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Half a barcode must not be looked up, and must never be complained about.
 *
 * The till auto-searches while the digits are still arriving, which is how a reader that sends no
 * terminator works at all. It did so 200 milliseconds after any keystroke with no length check, so it
 * ran against fragments of codes. The access log of one afternoon holds the result: lookups for "1",
 * "12", "124", "00", "19" and 28 other fragments, every one answered 404, every one raising a red error
 * over the counter — and four cases where a fragment was looked up seconds before the same code,
 * complete, resolved to a product. To the cashier the till was failing on goods that scan perfectly.
 *
 * Asserted against the source in the same way the shift closing wiring is, because what went wrong is
 * the wiring: a threshold, a delay, and which of the two lookups is allowed to speak.
 */
class BarcodeScanNoiseTest extends TestCase
{
    private function source(): string
    {
        $path = resource_path( 'ts/pages/dashboard/pos/ns-pos-barcode-bar.vue' );

        $this->assertFileExists( $path );

        return (string) File::get( $path );
    }

    public function test_a_fragment_of_a_barcode_is_not_looked_up(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'minimumAutoSearchLength',
            $source,
            'The auto search has no length threshold, so it runs against the first digit the reader'
                . ' sends and every one after it.'
        );

        $this->assertStringContainsString(
            'value.length < this.minimumAutoSearchLength',
            $source,
            'the threshold is declared but never applied before scheduling'
        );

        /**
         * The digits keep arriving while the timer waits, so the field has to be measured again when it
         * fires rather than trusting the value that scheduled it.
         */
        $this->assertSame(
            2,
            preg_match_all( '/< this\.minimumAutoSearchLength/', $source ),
            'the length is checked when the timer is set but not when it fires, so a fragment still'
                . ' reaches the server if the burst stops early'
        );
    }

    /**
     * 200ms is shorter than the burst a reader sends for a 13 digit code on a busy till, which is what
     * let the timer fire mid-code.
     */
    public function test_the_auto_search_waits_for_the_burst_to_finish(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression(
            '/submitSearch\( this\.barcode, false \);\s*\}, (\d+) \);/s',
            $source
        );

        preg_match( '/submitSearch\( this\.barcode, false \);\s*\}, (\d+) \);/s', $source, $matches );

        $this->assertGreaterThanOrEqual(
            400,
            (int) $matches[ 1 ],
            'The auto search still fires before a reader can finish a 13 digit code.'
        );
    }

    /**
     * Only the lookup someone is waiting for may raise an error. The background one runs on its own and
     * a failure there describes an unfinished code, not a missing product.
     */
    public function test_only_the_lookup_the_cashier_asked_for_reports_a_failure(): void
    {
        $source = $this->source();

        $errorHandler = strstr( $source, 'error: ( error ) => {' );

        $this->assertNotFalse( $errorHandler, 'the error handler is gone' );

        $guardPosition = strpos( $errorHandler, 'if ( ! userInitiated ) {' );
        $snackbarPosition = strpos( $errorHandler, 'nsSnackBar.error(' );

        $this->assertNotFalse(
            $guardPosition,
            'A failed background lookup still raises an error over the till, which is what put a wall of'
                . ' red on products that scan perfectly well.'
        );

        $this->assertLessThan(
            $snackbarPosition,
            $guardPosition,
            'the guard sits after the error is raised, so it changes nothing'
        );
    }

    /**
     * The Enter a reader sends after the digits stays authoritative: it carries the whole code and it
     * must still report when that code is genuinely unknown.
     */
    public function test_pressing_enter_still_reports_an_unknown_code(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            '@keyup.enter="submitSearch( barcode )"',
            $source,
            'the Enter path no longer submits, so a reader that sends a terminator stops working'
        );

        /**
         * submitSearch defaults to userInitiated, so the Enter path above is the one allowed to speak.
         */
        $this->assertStringContainsString( 'submitSearch( rawValue, userInitiated = true )', $source );
    }
}
