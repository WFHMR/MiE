<?php

namespace Tests\Feature;

use App\Models\VatRate;
use App\Services\ThaiVatService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Covers the Thai VAT engine.
 *
 * Two properties matter more than any single number here. First, a sale must be
 * taxed with the rate that was in force on the day it happened, because the
 * reduced 7% rate comes from a Royal Decree that is renewed annually and a
 * reprinted tax invoice has to match what was already reported. Second, net plus
 * VAT must equal the gross the customer actually paid, to the satang — a tax
 * invoice whose lines do not add up to its total is not a valid document.
 */
class ThaiVatServiceTest extends TestCase
{
    /**
     * Rate periods are shop wide. The suite shares one sqlite fixture with no
     * RefreshDatabase, so opening periods here has to roll back or every later test
     * that touches an order would tax it at whatever this file left behind.
     */
    use DatabaseTransactions;

    private ThaiVatService $vat;

    private mixed $previousPrecision = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vat = app()->make( ThaiVatService::class );

        /**
         * Thai money is satang, so two decimals. Pinned explicitly because other
         * tests in the suite write ns_currency_precision to the shared fixture, and
         * the amounts asserted below only make sense at the precision the shop
         * actually uses.
         */
        $this->previousPrecision = ns()->option->get( 'ns_currency_precision' );
        ns()->option->set( 'ns_currency_precision', 2 );
    }

    protected function tearDown(): void
    {
        if ( $this->previousPrecision !== null ) {
            ns()->option->set( 'ns_currency_precision', $this->previousPrecision );
        }

        parent::tearDown();
    }

    public function test_it_exposes_the_three_thai_classifications(): void
    {
        $statuses = array_keys( $this->vat->statuses() );

        $this->assertSame(
            [ ThaiVatService::STATUS_STANDARD, ThaiVatService::STATUS_EXEMPT, ThaiVatService::STATUS_ZERO_RATED ],
            $statuses
        );
    }

    public function test_an_unknown_classification_falls_back_to_the_shop_default(): void
    {
        ns()->option->set( 'ns_thai_vat_default_status', ThaiVatService::STATUS_STANDARD );

        $this->assertSame( ThaiVatService::STATUS_STANDARD, $this->vat->normalizeStatus( null ) );
        $this->assertSame( ThaiVatService::STATUS_STANDARD, $this->vat->normalizeStatus( 'nonsense' ) );
        $this->assertSame( ThaiVatService::STATUS_EXEMPT, $this->vat->normalizeStatus( ThaiVatService::STATUS_EXEMPT ) );

        /**
         * A corrupted default must not leak an invalid status into an order line.
         */
        ns()->option->set( 'ns_thai_vat_default_status', 'also-nonsense' );

        $this->assertSame( ThaiVatService::STATUS_STANDARD, $this->vat->normalizeStatus( null ) );

        ns()->option->set( 'ns_thai_vat_default_status', ThaiVatService::STATUS_STANDARD );
    }

    public function test_a_gross_price_splits_exactly_at_seven_percent(): void
    {
        /**
         * 107.00 including 7% is 100.00 net and 7.00 tax with no remainder. If this
         * drifts, every other amount is wrong too.
         */
        $this->assertSame( 100.0, $this->vat->netFromGross( 107.0, 7.0 ) );
        $this->assertSame( 7.0, $this->vat->vatFromGross( 107.0, 7.0 ) );
        $this->assertSame( 107.0, $this->vat->grossFromNet( 100.0, 7.0 ) );
    }

    public function test_net_plus_vat_always_reconstructs_the_gross(): void
    {
        $amounts = [ 0.01, 0.99, 1.0, 3.33, 9.95, 12.5, 19.99, 45.5, 107.0, 249.75, 1000.0, 15342.87 ];

        foreach ( $amounts as $gross ) {
            $net = $this->vat->netFromGross( $gross, 7.0 );
            $vat = $this->vat->vatFromGross( $gross, 7.0 );

            $this->assertSame(
                round( $gross, 2 ),
                round( $net + $vat, 2 ),
                sprintf( 'net (%s) + vat (%s) does not add up to the gross %s', $net, $vat, $gross )
            );
        }
    }

    /**
     * The reconstruction has to hold at whatever precision the shop configured, not
     * only at two decimals. A shop set to whole baht cannot represent satang, so the
     * split is against the rounded gross — but it still has to balance, otherwise the
     * receipt total and the VAT line disagree.
     */
    public function test_net_plus_vat_reconstructs_the_gross_at_whole_baht_precision(): void
    {
        ns()->option->set( 'ns_currency_precision', 0 );

        foreach ( [ 0.01, 0.99, 1.0, 9.95, 107.0, 249.75, 15342.87 ] as $gross ) {
            $net = $this->vat->netFromGross( $gross, 7.0 );
            $vat = $this->vat->vatFromGross( $gross, 7.0 );

            $this->assertSame(
                round( $gross, 0 ),
                round( $net + $vat, 0 ),
                sprintf( 'net (%s) + vat (%s) does not add up to the rounded gross %s', $net, $vat, $gross )
            );
        }

        ns()->option->set( 'ns_currency_precision', 2 );
    }

    public function test_a_zero_or_negative_rate_leaves_the_amount_untouched(): void
    {
        $this->assertSame( 50.0, $this->vat->netFromGross( 50.0, 0.0 ) );
        $this->assertSame( 0.0, $this->vat->vatFromGross( 50.0, 0.0 ) );
        $this->assertSame( 50.0, $this->vat->grossFromNet( 50.0, 0.0 ) );
    }

    public function test_exempt_and_zero_rated_both_carry_no_tax_but_differ_on_the_return(): void
    {
        $this->givenOnlyRate( 7.0, '2000-01-01' );

        $standard = $this->vat->breakdownFromGross( 107.0, ThaiVatService::STATUS_STANDARD );
        $exempt = $this->vat->breakdownFromGross( 107.0, ThaiVatService::STATUS_EXEMPT );
        $zeroRated = $this->vat->breakdownFromGross( 107.0, ThaiVatService::STATUS_ZERO_RATED );

        $this->assertSame( 7.0, $standard[ 'vat' ] );
        $this->assertSame( 100.0, $standard[ 'net' ] );
        $this->assertTrue( $standard[ 'taxable' ] );

        $this->assertSame( 0.0, $exempt[ 'vat' ] );
        $this->assertSame( 107.0, $exempt[ 'net' ] );
        $this->assertSame( 0.0, $exempt[ 'rate' ] );

        $this->assertSame( 0.0, $zeroRated[ 'vat' ] );
        $this->assertSame( 107.0, $zeroRated[ 'net' ] );
        $this->assertSame( 0.0, $zeroRated[ 'rate' ] );

        /**
         * The whole reason both statuses exist: an exempt supply is outside the VAT
         * system, a zero rated one is inside it at 0%. The PP30 return reports them
         * on separate lines, so the flag cannot be derived from the rate.
         */
        $this->assertFalse( $exempt[ 'taxable' ] );
        $this->assertTrue( $zeroRated[ 'taxable' ] );
    }

    public function test_the_rate_is_resolved_from_the_date_of_the_sale(): void
    {
        VatRate::query()->delete();

        VatRate::factory()->create( [
            'label' => 'VAT 10%',
            'rate' => 10,
            'effective_from' => '2015-01-01',
            'effective_to' => '2019-12-31',
        ] );

        VatRate::factory()->create( [
            'label' => 'VAT 7%',
            'rate' => 7,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
        ] );

        $this->assertSame( 10.0, $this->vat->rateOn( '2018-06-15' ) );
        $this->assertSame( 7.0, $this->vat->rateOn( '2020-01-01' ) );
        $this->assertSame( 7.0, $this->vat->rateOn( '2026-09-08' ) );

        /**
         * A sale dated before any declared period has no rate to resolve. Falling
         * back to the option rather than to zero keeps an imported historical order
         * from looking tax free.
         */
        ns()->option->set( 'ns_thai_vat_rate', 7 );
        $this->assertSame( 7.0, $this->vat->rateOn( '2001-01-01' ) );
    }

    public function test_opening_a_period_closes_the_previous_one_without_leaving_a_gap(): void
    {
        $this->givenOnlyRate( 7.0, '2020-01-01' );

        $this->vat->openRatePeriod( rate: 10.0, from: '2027-10-01' );

        $previous = VatRate::whereDate( 'effective_from', '2020-01-01' )->firstOrFail();

        $this->assertSame( '2027-09-30', $previous->effective_to->toDateString() );
        $this->assertSame( 7.0, $this->vat->rateOn( '2027-09-30' ) );
        $this->assertSame( 10.0, $this->vat->rateOn( '2027-10-01' ) );

        /**
         * Every day between the two periods has to resolve to exactly one rate.
         */
        $this->assertSame( 1, VatRate::effectiveOn( '2027-09-30' )->count() );
        $this->assertSame( 1, VatRate::effectiveOn( '2027-10-01' )->count() );
    }

    public function test_reopening_the_same_start_date_replaces_the_period_instead_of_duplicating_it(): void
    {
        $this->givenOnlyRate( 7.0, '2026-10-01' );

        $this->vat->openRatePeriod( rate: 8.0, from: '2026-10-01' );

        $this->assertSame( 1, VatRate::count() );
        $this->assertSame( 8.0, $this->vat->rateOn( '2026-10-02' ) );
    }

    public function test_a_period_cannot_end_before_it_starts(): void
    {
        $this->expectException( \InvalidArgumentException::class );

        $this->vat->openRatePeriod( rate: 7.0, from: '2026-10-01', to: '2026-09-01' );
    }

    public function test_ensure_rate_exists_creates_one_when_the_table_is_empty(): void
    {
        VatRate::query()->delete();

        $this->assertNull( $this->vat->rateRecordOn() );

        $created = $this->vat->ensureRateExists();

        $this->assertInstanceOf( VatRate::class, $created );
        $this->assertSame( ThaiVatService::DEFAULT_RATE, (float) $created->rate );
        $this->assertNull( $created->effective_to );
        $this->assertSame( ThaiVatService::DEFAULT_RATE, $this->vat->rateOn() );
    }

    public function test_it_validates_the_thai_taxpayer_identification_checksum(): void
    {
        /**
         * 0105536001107 — the last digit is the checksum over the first twelve, each
         * weighted by its position counting down from 13.
         */
        $this->assertTrue( $this->vat->isValidTaxpayerId( '0105536001107' ) );

        /**
         * Formatting is stripped before checking, since the number is printed with
         * separators on official documents.
         */
        $this->assertTrue( $this->vat->isValidTaxpayerId( '0-1055-36001-10-7' ) );

        /**
         * Same digits, wrong checksum.
         */
        $this->assertFalse( $this->vat->isValidTaxpayerId( '0105536001108' ) );

        $this->assertFalse( $this->vat->isValidTaxpayerId( '010553600110' ) );
        $this->assertFalse( $this->vat->isValidTaxpayerId( '01055360011077' ) );
        $this->assertFalse( $this->vat->isValidTaxpayerId( '' ) );
        $this->assertFalse( $this->vat->isValidTaxpayerId( null ) );
        $this->assertFalse( $this->vat->isValidTaxpayerId( 'abcdefghijklm' ) );
    }

    public function test_a_shop_is_not_vat_registered_without_a_valid_taxpayer_id(): void
    {
        ns()->option->set( 'ns_thai_vat_registered', 'yes' );
        ns()->option->set( 'ns_thai_tax_id', '0105536001108' );

        $this->assertFalse( $this->vat->isRegistered() );

        ns()->option->set( 'ns_thai_tax_id', '0105536001107' );
        $this->assertTrue( $this->vat->isRegistered() );

        ns()->option->set( 'ns_thai_vat_registered', 'no' );
        $this->assertFalse( $this->vat->isRegistered() );
    }

    public function test_the_taxpayer_profile_defaults_the_branch_to_head_office(): void
    {
        ns()->option->set( 'ns_thai_tax_branch_code', '' );

        $this->assertSame(
            ThaiVatService::HEAD_OFFICE_BRANCH_CODE,
            $this->vat->taxpayerProfile()[ 'branch_code' ]
        );

        ns()->option->set( 'ns_thai_tax_branch_code', '00003' );

        $this->assertSame( '00003', $this->vat->taxpayerProfile()[ 'branch_code' ] );
    }

    public function test_leading_zeros_survive_the_option_round_trip(): void
    {
        /**
         * Options::decodeOptionValue() casts any digit-only option to an integer, so
         * these two values come back as 105536001107 and 0. Every Thai taxpayer number
         * starts with a zero and head office is literally "00000", so reading them raw
         * yields an invalid number and an invalid branch code on every tax invoice.
         */
        ns()->option->set( 'ns_thai_tax_id', '0105536001107' );
        ns()->option->set( 'ns_thai_tax_branch_code', '00000' );

        $this->assertSame( 105536001107, ns()->option->get( 'ns_thai_tax_id' ), 'The option layer is expected to be lossy here.' );
        $this->assertSame( 0, ns()->option->get( 'ns_thai_tax_branch_code' ), 'The option layer is expected to be lossy here.' );

        $this->assertSame( '0105536001107', $this->vat->taxpayerId() );
        $this->assertSame( '00000', $this->vat->branchCode() );
        $this->assertTrue( $this->vat->isValidTaxpayerId( $this->vat->taxpayerId() ) );

        ns()->option->set( 'ns_thai_tax_branch_code', '00012' );
        $this->assertSame( '00012', $this->vat->branchCode() );

        /**
         * Nothing entered has to stay nothing, otherwise an unconfigured shop would
         * look like head office with a zero filled taxpayer number.
         */
        ns()->option->set( 'ns_thai_tax_id', '' );
        $this->assertSame( '', $this->vat->taxpayerId() );
        $this->assertFalse( $this->vat->isRegistered() );
    }

    /**
     * The receipt reads a different option from the tax invoice, and it was reading it raw.
     *
     * ns_store_tax_id feeds the printed receipt, ns_thai_tax_id feeds the tax invoice. The invoice
     * path went through taxpayerId() and was padded; the receipt did not, so a taxpayer number
     * beginning with zero was printed a digit short on every sale. formatTaxpayerId() exists so any
     * caller reading an option directly can restore the number without duplicating the padding.
     */
    public function test_a_store_taxpayer_number_read_from_the_options_keeps_its_leading_zero(): void
    {
        ns()->option->set( 'ns_store_tax_id', '0223554000076' );

        /**
         * Pinned to document why the padding is needed at all: the option layer is lossy here, and a
         * twelve digit taxpayer number is not a valid one.
         */
        $this->assertSame( 223554000076, ns()->option->get( 'ns_store_tax_id' ), 'The option layer is expected to be lossy here.' );

        $restored = $this->vat->formatTaxpayerId( ns()->option->get( 'ns_store_tax_id' ) );

        $this->assertSame( '0223554000076', $restored );
        $this->assertSame( 13, strlen( $restored ) );
    }

    public function test_formatting_a_taxpayer_number_strips_punctuation_and_leaves_nothing_as_nothing(): void
    {
        /**
         * The receipt builder placeholder shows the dashed form, so an owner may well type it that
         * way. Only the digits belong on the document.
         */
        $this->assertSame( '0105536001107', $this->vat->formatTaxpayerId( '0-1055-36001-10-7' ) );

        /**
         * A shop that has entered nothing must print nothing, not thirteen zeros, otherwise an
         * unconfigured till issues receipts carrying a number that looks deliberate.
         */
        $this->assertSame( '', $this->vat->formatTaxpayerId( '' ) );
        $this->assertSame( '', $this->vat->formatTaxpayerId( null ) );

        /**
         * An integer arrives here whenever the value came back through the option layer.
         */
        $this->assertSame( '0105536001107', $this->vat->formatTaxpayerId( 105536001107 ) );
    }

    /**
     * Leaves exactly one open ended rate period in place.
     */
    private function givenOnlyRate( float $rate, string $from ): void
    {
        VatRate::query()->delete();

        VatRate::factory()->create( [
            'label' => 'VAT ' . $rate . '%',
            'rate' => $rate,
            'effective_from' => $from,
            'effective_to' => null,
        ] );
    }
}
