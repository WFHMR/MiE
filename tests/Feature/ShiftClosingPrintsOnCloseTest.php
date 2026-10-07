<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Closing a till has to produce the slip.
 *
 * The employee hands the shift closing slip over together with the cash, so it belongs to the act of
 * closing. It was not wired to it: the only way to obtain the document was a "Print Z-Report" button
 * inside the History popup, which nobody opens at the end of a shift. The shop closed shifts for days
 * and the access log recorded **not one** request for the document — the operator pressed ปิดกะ and
 * nothing came out.
 *
 * Asserted against the source rather than the browser, in the same way BranchPickerWiringTest pins
 * the header wiring. It is the printing call and the order it happens in that went missing, and both
 * are readable here. The figures on the document itself are covered by ShiftClosingSummaryTest.
 */
class ShiftClosingPrintsOnCloseTest extends TestCase
{
    use WithAuthentication;

    private function optionsPopup(): string
    {
        $path = resource_path( 'ts/popups/ns-pos-cash-registers-options-popup.vue' );

        $this->assertFileExists( $path );

        return (string) file_get_contents( $path );
    }

    public function test_closing_a_register_prints_the_shift_closing_slip(): void
    {
        $source = $this->optionsPopup();

        $this->assertStringContainsString(
            "POS.print.process(",
            $source,
            'Closing a register does not print anything. The employee presses ปิดกะ, hands over the'
                . ' cash, and has no slip to hand over with it.'
        );

        $this->assertStringContainsString(
            "'z-report'",
            $source,
            'The close path prints something other than the shift closing document.'
        );
    }

    /**
     * The document is fetched by register id, and closing drops the till reference from the POS. Print
     * first or there is nothing left to identify what to print.
     */
    public function test_the_slip_is_printed_before_the_register_reference_is_dropped(): void
    {
        $source = $this->optionsPopup();

        $printPosition = strpos( $source, 'POS.print.process(' );
        $unsetPosition = strpos( $source, "POS.unset( 'register' )" );

        $this->assertNotFalse( $printPosition, 'the close path never prints' );
        $this->assertNotFalse( $unsetPosition, 'the close path no longer clears the register reference' );

        $this->assertLessThan(
            $unsetPosition,
            $printPosition,
            'The slip is printed after the till reference is dropped, so the id it is fetched by has'
                . ' already gone and nothing prints.'
        );
    }

    /**
     * A till that cannot reach a printer must still be allowed to close. The shift is recorded before
     * this point, and the slip can be reprinted from History.
     */
    public function test_a_printing_failure_does_not_block_the_close(): void
    {
        $source = $this->optionsPopup();

        $printPosition = strpos( $source, 'POS.print.process(' );

        $this->assertNotFalse( $printPosition );

        $guarded = substr( $source, max( 0, $printPosition - 400 ), 600 );

        $this->assertStringContainsString(
            'try',
            $guarded,
            'Printing is not guarded. A till with no printer configured would throw on close, and the'
                . ' cashier would be unable to finish their shift.'
        );
    }

    /**
     * Where the first fix actually died.
     *
     * The printing adapter resolves a document to a URL through a table of names, and it looks those
     * names up in whatever the page handed it. The orders pages hand it two page globals, systemUrls
     * and systemOptions. The POS page declares neither — it hands the same information to the POS
     * object instead — and it never carried an address for the shift closing slip at all. So the call
     * added on close resolved nothing, and the till reported a failed print with no request ever
     * leaving the browser: the access log held zero hits for the document while shifts were closed.
     *
     * Asserted on the rendered page because that is what the till receives.
     */
    public function test_the_pos_page_hands_the_till_an_address_for_the_slip(): void
    {
        $response = $this->actingAs( $this->attemptGetAnyUserFromRole(), 'web' )
            ->get( 'dashboard/pos' );

        $response->assertOk();

        /**
         * Slashes are escaped by json_encode inside the template, so the address is compared with
         * the escaping removed rather than by guessing at its exact encoded form.
         */
        $page = str_replace( '\/', '/', (string) $response->getContent() );

        $this->assertStringContainsString(
            'z_report_printing_url',
            $page,
            'The POS page never tells the till where the shift closing slip lives, so pressing ปิดกะ'
                . ' cannot fetch it.'
        );

        $this->assertStringContainsString(
            'dashboard/cash-registers/z-report/{id}',
            $page,
            'The address handed to the till does not point at the shift closing document.'
        );

        $this->assertStringContainsString(
            'z-report/{id}?dash-visibility=disabled&autoprint=true',
            $page,
            'The slip is fetched without autoprint, so it opens in a hidden frame and sits there'
                . ' without ever reaching the printer.'
        );
    }

    /**
     * The address only helps if the adapter is looking in the right place for it.
     */
    public function test_the_printing_adapter_is_told_about_the_pos_context(): void
    {
        $source = (string) file_get_contents( resource_path( 'ts/pos-init.ts' ) );

        $this->assertStringContainsString(
            'this.print.setContext({ urls: settings.urls })',
            $source,
            'The POS never passes its own URLs to the printing adapter, which therefore keeps looking'
                . ' them up on the page globals the POS page does not declare.'
        );

        $this->assertStringContainsString(
            'this.print.setContext({ options })',
            $source,
            'The POS never passes its options to the printing adapter, so the adapter cannot tell'
                . ' whether to use the browser dialog or a printing plugin.'
        );

        /**
         * Reading an identifier that was never declared throws, and this read sits in the POS
         * constructor — the throw would abort the module before window.POS exists, taking the whole
         * till with it rather than just the printing.
         */
        $this->assertStringContainsString(
            "typeof systemUrls !== 'undefined'",
            $source,
            'The POS reads a page global that does not exist on the POS page without guarding it.'
        );

        $this->assertStringContainsString(
            "typeof systemOptions !== 'undefined'",
            $source,
            'The POS reads a page global that does not exist on the POS page without guarding it.'
        );
    }

    /**
     * The two pages template the same URLs differently: {reference_id} on the dashboard, {id} on the
     * POS. Replacing only one of them leaves the placeholder in the address and fetches a 404 into a
     * hidden frame, which looks exactly like a printer that did nothing.
     */
    public function test_the_printing_adapter_understands_both_url_placeholders(): void
    {
        $source = (string) file_get_contents( resource_path( 'ts/libraries/print.ts' ) );

        $this->assertStringContainsString( "replace( '{reference_id}', reference_id )", $source );
        $this->assertStringContainsString( "replace( '{id}', reference_id )", $source );

        $this->assertStringContainsString(
            'return false;',
            $source,
            'A missing printing address is swallowed instead of being reported back, so every caller'
                . ' believes the document was printed.'
        );
    }

    /**
     * The built bundle is what the browser runs. A source change that was never compiled is invisible
     * to the shop, which is the failure mode this whole fix is about.
     */
    public function test_the_built_bundle_contains_the_printing_call(): void
    {
        $assets = glob( public_path( 'build/assets/*.js' ) );

        $this->assertNotEmpty( $assets, 'there is no built frontend at all' );

        /**
         * Matched on the newest strings this work introduced, never on "z-report".
         *
         * The bundle has contained "z-report" since long before any of this, for the History popup's
         * button, so looking for that would pass against a bundle compiled before the fix — which is
         * precisely the state that left the shop pressing ปิดกะ and getting nothing. Each round of
         * work therefore pins the newest literal it added: the first added the close-path warning,
         * the second added the receipt warning that came with wiring the adapter to the POS.
         */
        $markers = [
            'พิมพ์ใบปิดกะไม่สำเร็จ',
            'พิมพ์ใบเสร็จไม่สำเร็จ',
        ];

        $sources = array_map(
            fn ( string $asset ): string => (string) file_get_contents( $asset ),
            $assets
        );

        foreach ( $markers as $marker ) {
            $found = false;

            foreach ( $sources as $source ) {
                if ( str_contains( $source, $marker ) ) {
                    $found = true;

                    break;
                }
            }

            $this->assertTrue(
                $found,
                'The compiled frontend is older than the source: no bundle contains "' . $marker . '".'
                    . ' The shop would press ปิดกะ and still get nothing. Run npm run build and deploy'
                    . ' public/build.'
            );
        }
    }
}
