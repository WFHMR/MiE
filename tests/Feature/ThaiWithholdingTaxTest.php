<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\Role;
use App\Models\VatRate;
use App\Models\WithholdingTax;
use App\Models\WithholdingTaxSequence;
use App\Services\ThaiWithholdingTaxService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers Thai withholding tax on the paying side.
 *
 * The properties under test are the ones the Revenue Department or the payee would catch:
 * the tax is computed on the amount before VAT and not on the invoice total, the monthly
 * return a record lands on follows from what the payee is rather than from a free choice,
 * the certificate number is sequential and never reused, a cancelled certificate leaves the
 * monthly figures, and a certificate reproduces the payee as they were when paid.
 */
class ThaiWithholdingTaxTest extends TestCase
{
    use DatabaseTransactions;

    private const SHOP_TAX_ID = '0105536001107';

    private const PAYEE_TAX_ID = '0994000158441';

    private ThaiWithholdingTaxService $withholding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withholding = app()->make( ThaiWithholdingTaxService::class );

        ns()->option->set( 'ns_currency_precision', 2 );
        ns()->option->set( 'ns_thai_wht_prefix', 'WHT' );
        ns()->option->set( 'ns_thai_wht_start_number', 0 );

        $this->givenWithholdingAgent();
        $this->givenVatRate( 7 );
    }

    public function test_the_tax_is_computed_on_the_amount_before_vat(): void
    {
        /**
         * The single most common error in this area. A 107.00 service invoice at 7% VAT is
         * 100.00 of service, so 3% withholding is 3.00 — not 3.21. Getting this wrong
         * short-pays the payee and puts the certificate out of step with their own books.
         */
        $breakdown = $this->withholding->breakdown( grossAmount: 107.0, rate: 3.0 );

        $this->assertEqualsWithDelta( 100.0, $breakdown[ 'base' ], 0.01 );
        $this->assertEqualsWithDelta( 7.0, $breakdown[ 'vat' ], 0.01 );
        $this->assertEqualsWithDelta( 3.0, $breakdown[ 'tax' ], 0.01 );

        /**
         * What the shop actually transfers: the invoice total less the tax kept back.
         */
        $this->assertEqualsWithDelta( 104.0, $breakdown[ 'net_payable' ], 0.01 );
    }

    public function test_a_payee_outside_the_vat_system_is_not_stripped_of_vat(): void
    {
        /**
         * A payee below the registration threshold charges no VAT, so the amount they
         * invoiced already is the base. Stripping 7% out of it would under-withhold.
         */
        $breakdown = $this->withholding->breakdown( grossAmount: 100.0, rate: 3.0, vatRegistered: false );

        $this->assertEqualsWithDelta( 100.0, $breakdown[ 'base' ], 0.01 );
        $this->assertEqualsWithDelta( 0.0, $breakdown[ 'vat' ], 0.01 );
        $this->assertEqualsWithDelta( 3.0, $breakdown[ 'tax' ], 0.01 );
    }

    public function test_only_the_four_statutory_rates_are_accepted(): void
    {
        foreach ( [ 1.0, 2.0, 3.0, 5.0 ] as $rate ) {
            $this->assertTrue( $this->withholding->isValidRate( $rate ), $rate . '% is a statutory rate.' );
        }

        foreach ( [ 0.0, 4.0, 7.0, 10.0, 15.0 ] as $rate ) {
            $this->assertFalse( $this->withholding->isValidRate( $rate ), $rate . '% is not a withholding rate.' );
        }
    }

    public function test_the_monthly_return_follows_from_the_payee_type(): void
    {
        /**
         * Not a free choice. An individual is always ภ.ง.ด.3 and a company always
         * ภ.ง.ด.53, and a mismatched pair is one of the things that gets a filing bounced.
         */
        $this->assertSame( WithholdingTax::FORM_PND3, $this->withholding->formFor( WithholdingTax::PAYEE_INDIVIDUAL ) );
        $this->assertSame( WithholdingTax::FORM_PND53, $this->withholding->formFor( WithholdingTax::PAYEE_JURISTIC ) );

        $individual = $this->withholding->issue( $this->givenPayment( payeeType: WithholdingTax::PAYEE_INDIVIDUAL ) );
        $company = $this->withholding->issue( $this->givenPayment( payeeType: WithholdingTax::PAYEE_JURISTIC ) );

        $this->assertSame( WithholdingTax::FORM_PND3, $individual->form );
        $this->assertSame( WithholdingTax::FORM_PND53, $company->form );
    }

    public function test_a_shop_that_is_not_a_withholding_agent_cannot_issue(): void
    {
        ns()->option->set( 'ns_thai_wht_enabled', 'no' );

        $this->expectException( NotAllowedException::class );

        /**
         * Only a registered business is a withholding agent. A certificate from a shop that
         * is not one is worthless to the payee.
         */
        $this->withholding->issue( $this->givenPayment() );
    }

    public function test_a_payee_taxpayer_id_that_fails_the_checksum_is_refused(): void
    {
        $this->expectException( NotAllowedException::class );

        /**
         * The Revenue Department matches a filing to a taxpayer by this number. A wrong one
         * means the withholding is never credited to the payee and they are taxed twice on
         * income already taxed.
         */
        $this->withholding->issue( $this->givenPayment( payeeTaxId: '0994000158442' ) );
    }

    public function test_an_unknown_income_category_is_refused(): void
    {
        $this->expectException( NotAllowedException::class );

        $this->withholding->issue( $this->givenPayment( incomeType: '40(99)' ) );
    }

    public function test_issuing_produces_a_numbered_certificate(): void
    {
        $record = $this->withholding->issue( $this->givenPayment() );

        $this->assertInstanceOf( WithholdingTax::class, $record );
        $this->assertMatchesRegularExpression( '/^WHT-\d{4}-\d{6}$/', $record->number );
        $this->assertSame( WithholdingTax::STATUS_ISSUED, $record->status );
        $this->assertSame( '00000', $record->payee_branch_code );
    }

    public function test_numbers_are_sequential_and_never_reused(): void
    {
        $first = $this->withholding->issue( $this->givenPayment() );
        $second = $this->withholding->issue( $this->givenPayment() );

        $this->assertSame( $first->sequence_number + 1, $second->sequence_number );

        /**
         * Cancelling must not free the number. Two certificates sharing a number on a filed
         * return is not something that can be quietly corrected later.
         */
        $this->withholding->cancel( $second, 'กรอกชื่อผู้รับเงินผิด' );
        $third = $this->withholding->issue( $this->givenPayment() );

        $this->assertSame( $second->sequence_number + 1, $third->sequence_number );
        $this->assertSame(
            $third->sequence_number,
            WithholdingTaxSequence::where( 'scope', $third->sequence_scope )->firstOrFail()->last_number
        );
    }

    public function test_the_certificate_keeps_its_own_copy_of_the_payee(): void
    {
        $record = $this->withholding->issue( $this->givenPayment(
            payeeName: 'บริษัท ผู้ให้บริการ จำกัด',
            payeeAddress: '11/1 ถนนทดสอบ กรุงเทพมหานคร',
        ) );

        $originalName = $record->payee_name;
        $originalAddress = $record->payee_address;

        /**
         * A later payment to the same payee at a new address must not rewrite a certificate
         * already handed over and already reported on a monthly return.
         */
        $this->withholding->issue( $this->givenPayment(
            payeeName: 'บริษัท ผู้ให้บริการ จำกัด (ย้ายที่อยู่)',
            payeeAddress: 'ที่อยู่ใหม่',
        ) );

        $reloaded = WithholdingTax::findOrFail( $record->id );

        $this->assertSame( $originalName, $reloaded->payee_name );
        $this->assertSame( $originalAddress, $reloaded->payee_address );
    }

    public function test_cancelling_requires_a_reason_and_keeps_the_document(): void
    {
        $record = $this->withholding->issue( $this->givenPayment() );

        try {
            $this->withholding->cancel( $record, '   ' );
            $this->fail( 'A cancellation without a reason must be refused.' );
        } catch ( NotAllowedException ) {
            // expected
        }

        $cancelled = $this->withholding->cancel( $record, 'จ่ายเงินซ้ำ' );

        $this->assertSame( WithholdingTax::STATUS_CANCELLED, $cancelled->status );
        $this->assertNotNull( $cancelled->cancelled_at );
        $this->assertNotNull( WithholdingTax::find( $record->id ), 'A cancelled certificate is kept, never deleted.' );

        $this->expectException( NotAllowedException::class );
        $this->withholding->cancel( $cancelled->refresh(), 'ยกเลิกซ้ำ' );
    }

    public function test_the_monthly_return_groups_by_payee_and_category(): void
    {
        $start = ns()->date->getNow()->startOfMonth()->toDateString();
        $end = ns()->date->getNow()->endOfMonth()->toDateString();

        $before = $this->withholding->getPndSummary( WithholdingTax::FORM_PND53, $start, $end );

        $this->withholding->issue( $this->givenPayment( gross: 107.0 ) );
        $this->withholding->issue( $this->givenPayment( gross: 214.0 ) );

        $after = $this->withholding->getPndSummary( WithholdingTax::FORM_PND53, $start, $end );

        $this->assertSame( $before[ 'count' ] + 2, $after[ 'count' ] );

        /**
         * 100 + 200 of base at 3% is 9 of tax. Both payments went to the same payee with the
         * same category and rate, so both forms show them as one line with a count of two.
         */
        $line = collect( $after[ 'payees' ] )->firstWhere( 'payee_tax_id', self::PAYEE_TAX_ID );

        $this->assertNotNull( $line );
        $this->assertGreaterThanOrEqual( 2, $line[ 'count' ] );
        $this->assertEqualsWithDelta( $before[ 'total_tax' ] + 9.0, $after[ 'total_tax' ], 0.02 );
        $this->assertEqualsWithDelta( $before[ 'total_base' ] + 300.0, $after[ 'total_base' ], 0.02 );
    }

    public function test_a_cancelled_certificate_leaves_the_monthly_return(): void
    {
        $start = ns()->date->getNow()->startOfMonth()->toDateString();
        $end = ns()->date->getNow()->endOfMonth()->toDateString();

        $record = $this->withholding->issue( $this->givenPayment( gross: 107.0 ) );
        $withRecord = $this->withholding->getPndSummary( WithholdingTax::FORM_PND53, $start, $end );

        $this->withholding->cancel( $record, 'ยกเลิกบิล' );

        $afterCancel = $this->withholding->getPndSummary( WithholdingTax::FORM_PND53, $start, $end );

        /**
         * A cancelled withholding was never remitted. Reporting it would overstate what the
         * shop owes for the month.
         */
        $this->assertEqualsWithDelta( $withRecord[ 'total_tax' ] - 3.0, $afterCancel[ 'total_tax' ], 0.02 );
        $this->assertSame( $withRecord[ 'count' ] - 1, $afterCancel[ 'count' ] );
    }

    public function test_the_two_returns_do_not_bleed_into_each_other(): void
    {
        $start = ns()->date->getNow()->startOfMonth()->toDateString();
        $end = ns()->date->getNow()->endOfMonth()->toDateString();

        $beforePnd3 = $this->withholding->getPndSummary( WithholdingTax::FORM_PND3, $start, $end )[ 'total_tax' ];

        $this->withholding->issue( $this->givenPayment( payeeType: WithholdingTax::PAYEE_JURISTIC, gross: 107.0 ) );

        $afterPnd3 = $this->withholding->getPndSummary( WithholdingTax::FORM_PND3, $start, $end )[ 'total_tax' ];

        $this->assertEqualsWithDelta( $beforePnd3, $afterPnd3, 0.02, 'A company payment has no business on the individuals return.' );
    }

    public function test_a_payment_outside_the_period_is_excluded(): void
    {
        $start = ns()->date->getNow()->startOfMonth()->toDateString();
        $end = ns()->date->getNow()->endOfMonth()->toDateString();

        $before = $this->withholding->getPndSummary( WithholdingTax::FORM_PND53, $start, $end )[ 'total_tax' ];

        $this->withholding->issue( $this->givenPayment(
            gross: 107.0,
            paidOn: ns()->date->getNow()->subMonthsNoOverflow( 3 )->toDateString(),
        ) );

        $after = $this->withholding->getPndSummary( WithholdingTax::FORM_PND53, $start, $end )[ 'total_tax' ];

        /**
         * The filing month follows paid_on, not created_at: a payment entered late still
         * belongs to the month the money left.
         */
        $this->assertEqualsWithDelta( $before, $after, 0.02 );
    }

    public function test_the_return_states_its_filing_deadline(): void
    {
        $summary = $this->withholding->getPndSummary(
            WithholdingTax::FORM_PND53,
            '2026-09-01',
            '2026-09-30'
        );

        /**
         * Both returns and the money are due by the 7th of the following month, and a late
         * filing carries a surcharge. That belongs in the payload, not in documentation.
         */
        $this->assertSame( '2026-10-07', $summary[ 'period' ][ 'due_date' ] );
        $this->assertNotEmpty( $summary[ 'notes' ] );
        $this->assertStringContainsString( 'ภ.ง.ด.53', implode( ' ', $summary[ 'notes' ] ) );
    }

    public function test_the_certificate_and_the_report_are_reachable(): void
    {
        $admin = Role::namespace( 'admin' )->users->first();
        $record = $this->withholding->issue( $this->givenPayment() );

        $this->actingAs( $admin )
            ->json( 'GET', '/dashboard/taxes/withholding/certificate/' . $record->id . '?dash-visibility=disabled' )
            ->assertStatus( 200 )
            ->assertSee( $record->number, false )
            ->assertSee( 'หนังสือรับรองการหักภาษี ณ ที่จ่าย', false );

        $this->actingAs( $admin )
            ->json( 'GET', '/dashboard/reports/withholding-tax' )
            ->assertStatus( 200 );

        Sanctum::actingAs( $admin, [ '*' ] );

        $this->json( 'POST', '/api/reports/withholding-tax', [
            'startDate' => ns()->date->getNow()->startOfMonth()->toDateString(),
            'endDate' => ns()->date->getNow()->endOfMonth()->toDateString(),
        ] )
            ->assertStatus( 200 )
            ->assertJsonStructure( [
                'pnd3' => [ 'form', 'form_label', 'period', 'payees', 'total_base', 'total_tax', 'count', 'notes' ],
                'pnd53' => [ 'form', 'form_label', 'period', 'payees', 'total_base', 'total_tax', 'count', 'notes' ],
            ] );
    }

    public function test_the_records_list_is_reachable(): void
    {
        $admin = Role::namespace( 'admin' )->users->first();

        $this->actingAs( $admin )
            ->json( 'GET', '/dashboard/taxes/withholding' )
            ->assertStatus( 200 );
    }

    private function givenWithholdingAgent(): void
    {
        ns()->option->set( 'ns_thai_wht_enabled', 'yes' );
        ns()->option->set( 'ns_thai_tax_id', self::SHOP_TAX_ID );
        ns()->option->set( 'ns_thai_tax_registered_name', 'บริษัท ทดสอบภาษี จำกัด' );
        ns()->option->set( 'ns_thai_tax_registered_address', '99/1 ถนนทดสอบ กรุงเทพมหานคร' );
        ns()->option->set( 'ns_thai_tax_branch_code', '00000' );
    }

    private function givenVatRate( float $rate ): void
    {
        VatRate::query()->delete();

        VatRate::factory()->create( [
            'label' => 'VAT ' . $rate . '%',
            'rate' => $rate,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
        ] );
    }

    /**
     * @return array<string, mixed>
     */
    private function givenPayment(
        string $payeeType = WithholdingTax::PAYEE_JURISTIC,
        float $gross = 107.0,
        float $rate = 3.0,
        string $incomeType = '40(8)',
        string $payeeName = 'บริษัท ผู้ให้บริการ จำกัด',
        string $payeeTaxId = self::PAYEE_TAX_ID,
        ?string $payeeAddress = null,
        ?string $paidOn = null,
    ): array {
        return [
            'payee_type' => $payeeType,
            'payee_name' => $payeeName,
            'payee_tax_id' => $payeeTaxId,
            'payee_address' => $payeeAddress,
            'income_type' => $incomeType,
            'income_description' => 'ค่าบริการทดสอบ',
            'rate' => $rate,
            'gross_amount' => $gross,
            'payee_vat_registered' => true,
            'paid_on' => $paidOn ?? ns()->date->getNow()->toDateString(),
        ];
    }
}
