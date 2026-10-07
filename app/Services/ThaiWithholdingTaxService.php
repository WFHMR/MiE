<?php

namespace App\Services;

use App\Exceptions\NotAllowedException;
use App\Models\WithholdingTax;
use App\Models\WithholdingTaxSequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Thai withholding tax (ภาษีหัก ณ ที่จ่าย) on the paying side.
 *
 * When a business pays for a service it keeps back a slice of the payment and remits it
 * to the Revenue Department on the payee's behalf. Three obligations follow, and all
 * three are what this service exists to get right:
 *
 *  1. Withhold the correct percentage of the correct base.
 *  2. Give the payee a certificate (หนังสือรับรองการหักภาษี ณ ที่จ่าย, form 50 ทวิ) so
 *     they can claim the tax already paid on their own return.
 *  3. File the month's withholdings by the 7th of the following month — ภ.ง.ด.3 for
 *     payees who are individuals, ภ.ง.ด.53 for payees who are companies.
 *
 * The base is the payment **excluding VAT**. Withholding 3% of a VAT inclusive 107 gives
 * 3.21 where the correct figure is 3.00, so the payee is short-paid by 21 satang and
 * their certificate does not agree with their own books. That single distinction is the
 * most common error in this area, which is why baseFromGross() exists and why the base is
 * stored on the record rather than reconstructed later.
 */
class ThaiWithholdingTaxService
{
    /**
     * Rate for transport services — Revenue Department Order Thor.Por. 4/2528, clause 12/4.
     */
    const RATE_TRANSPORT = 1.0;

    /**
     * Rate for advertising fees — clause 10.
     */
    const RATE_ADVERTISING = 2.0;

    /**
     * Rate for services, hire of work, professional fees, and rent of movable property.
     * The rate most retail shops meet most often.
     */
    const RATE_SERVICE = 3.0;

    /**
     * Rate for rent of immovable property, prizes and contest awards — Section 3 Tredecim.
     */
    const RATE_RENT_IMMOVABLE = 5.0;

    public function __construct(
        protected ThaiVatService $vatService,
        protected CurrencyService $currency,
    ) {}

    /**
     * The rates a shop can withhold at, with what each applies to.
     *
     * Keyed by the rate as a string so the value round-trips through a select field and
     * through Options, which casts a bare numeric string to an integer.
     *
     * @return array<string, string>
     */
    public function rates(): array
    {
        return [
            '1' => __( '1% — ค่าขนส่ง' ),
            '2' => __( '2% — ค่าโฆษณา' ),
            '3' => __( '3% — ค่าบริการ ค่าจ้างทำของ ค่าวิชาชีพอิสระ ค่าเช่าสังหาริมทรัพย์' ),
            '5' => __( '5% — ค่าเช่าอสังหาริมทรัพย์ รางวัล ส่วนลดจากการส่งเสริมการขาย' ),
        ];
    }

    /**
     * The section 40 income categories, which are a column on both monthly returns.
     *
     * @return array<string, string>
     */
    public function incomeTypes(): array
    {
        return [
            '40(1)' => __( '40(1) เงินเดือน ค่าจ้าง' ),
            '40(2)' => __( '40(2) ค่านายหน้า ค่าจ้างทั่วไป' ),
            '40(3)' => __( '40(3) ค่าลิขสิทธิ์ ค่าสิทธิ' ),
            '40(4)' => __( '40(4) ดอกเบี้ย เงินปันผล' ),
            '40(5)' => __( '40(5) ค่าเช่าทรัพย์สิน' ),
            '40(6)' => __( '40(6) วิชาชีพอิสระ (แพทย์ ทนาย วิศวกร สถาปนิก บัญชี ประณีตศิลป์)' ),
            '40(7)' => __( '40(7) ค่าจ้างรับเหมา' ),
            '40(8)' => __( '40(8) เงินได้อื่น ๆ รวมค่าบริการ ค่าขนส่ง ค่าโฆษณา' ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function payeeTypes(): array
    {
        return [
            WithholdingTax::PAYEE_INDIVIDUAL => __( 'บุคคลธรรมดา (ยื่น ภ.ง.ด.3)' ),
            WithholdingTax::PAYEE_JURISTIC => __( 'นิติบุคคล (ยื่น ภ.ง.ด.53)' ),
        ];
    }

    /**
     * Which monthly return a payee belongs on.
     *
     * Derived rather than chosen. The two are not independent — an individual is always
     * ภ.ง.ด.3 and a company always ภ.ง.ด.53 — and a mismatched pair is one of the things
     * that gets a filing bounced.
     */
    public function formFor( string $payeeType ): string
    {
        return $payeeType === WithholdingTax::PAYEE_JURISTIC
            ? WithholdingTax::FORM_PND53
            : WithholdingTax::FORM_PND3;
    }

    /**
     * Whether the value is one of the four rates a shop withholds at.
     */
    public function isValidRate( float|string|null $rate ): bool
    {
        if ( $rate === null || $rate === '' ) {
            return false;
        }

        return in_array( (float) $rate, [
            self::RATE_TRANSPORT,
            self::RATE_ADVERTISING,
            self::RATE_SERVICE,
            self::RATE_RENT_IMMOVABLE,
        ], true );
    }

    /**
     * The tax to withhold from a payment, given the base excluding VAT.
     */
    public function computeTax( float $baseAmount, float $rate ): float
    {
        if ( $baseAmount <= 0 || $rate <= 0 ) {
            return 0.0;
        }

        return $this->round( $baseAmount * $rate / 100 );
    }

    /**
     * The base to withhold on, extracted from a VAT inclusive invoice total.
     *
     * A supplier invoice normally shows the VAT inclusive figure, and that is not what the
     * rate applies to. Passing $vatRegistered = false returns the amount unchanged, which
     * is correct for a payee below the registration threshold — they charge no VAT, so the
     * amount they invoiced already is the base.
     */
    public function baseFromGross( float $grossAmount, bool $vatRegistered = true, Carbon|string|null $date = null ): float
    {
        if ( $grossAmount <= 0 ) {
            return 0.0;
        }

        if ( ! $vatRegistered ) {
            return $this->round( $grossAmount );
        }

        $vatRate = $this->vatService->rateOn( $date );

        return $this->vatService->netFromGross( $grossAmount, $vatRate );
    }

    /**
     * A full breakdown of one payment, for a form or a preview.
     *
     * @return array{ gross: float, base: float, vat: float, rate: float, tax: float, net_payable: float, form: string }
     */
    public function breakdown(
        float $grossAmount,
        float $rate,
        string $payeeType = WithholdingTax::PAYEE_JURISTIC,
        bool $vatRegistered = true,
        Carbon|string|null $date = null,
    ): array {
        $base = $this->baseFromGross( $grossAmount, $vatRegistered, $date );
        $tax = $this->computeTax( $base, $rate );

        return [
            'gross' => $this->round( $grossAmount ),
            'base' => $base,
            'vat' => $this->round( $this->round( $grossAmount ) - $base ),
            'rate' => $rate,
            'tax' => $tax,

            /**
             * What the payee actually receives: the invoice total less the tax kept back.
             * Shown because this is the figure the shop transfers, and getting it wrong is
             * what the payee notices.
             */
            'net_payable' => $this->round( $this->round( $grossAmount ) - $tax ),
            'form' => $this->formFor( $payeeType ),
        ];
    }

    /**
     * Issues a certificate for a payment.
     *
     * @param array{ payee_name: string, payee_tax_id: string, payee_type?: string, payee_branch_code?: string, payee_address?: string, income_type: string, income_description?: string, rate: float|string, base_amount?: float, gross_amount?: float, payee_vat_registered?: bool, paid_on?: string, transaction_id?: int|null } $fields
     *
     * @throws NotAllowedException
     */
    public function issue( array $fields, ?int $authorId = null ): WithholdingTax
    {
        $payeeType = $fields[ 'payee_type' ] ?? WithholdingTax::PAYEE_JURISTIC;
        $rate = (float) ( $fields[ 'rate' ] ?? 0 );
        $paidOn = ! empty( $fields[ 'paid_on' ] )
            ? Carbon::parse( $fields[ 'paid_on' ] )
            : ns()->date->getNow();

        /**
         * The base is taken as given when the caller knows it, and derived from the VAT
         * inclusive total otherwise. Preferring the explicit value matters: a supplier can
         * invoice a mix of VAT and non-VAT lines, and only the caller knows the split.
         */
        $gross = (float) ( $fields[ 'gross_amount' ] ?? 0 );
        $base = isset( $fields[ 'base_amount' ] ) && (float) $fields[ 'base_amount' ] > 0
            ? (float) $fields[ 'base_amount' ]
            : $this->baseFromGross( $gross, (bool) ( $fields[ 'payee_vat_registered' ] ?? true ), $paidOn );

        $this->assertCanIssue( $fields, $payeeType, $rate, $base );

        $tax = $this->computeTax( $base, $rate );

        /**
         * The number is allocated inside the transaction that writes the record, so a
         * failure cannot burn a number and leave a gap in the series.
         */
        return DB::transaction( function () use ( $fields, $payeeType, $rate, $base, $gross, $tax, $paidOn, $authorId ) {
            $scope = $this->currentScope( $paidOn );
            $number = $this->allocateNumber( $scope );

            $record = new WithholdingTax;
            $record->sequence_scope = $scope;
            $record->sequence_number = $number;
            $record->number = $this->formatNumber( $scope, $number );
            $record->form = $this->formFor( $payeeType );
            $record->payee_type = $payeeType;

            $record->payee_name = trim( $fields[ 'payee_name' ] );
            $record->payee_tax_id = $this->normalizeTaxId( $fields[ 'payee_tax_id' ] );
            $record->payee_branch_code = $this->normalizeBranchCode( $fields[ 'payee_branch_code' ] ?? null );
            $record->payee_address = $fields[ 'payee_address' ] ?? null;

            $record->income_type = $fields[ 'income_type' ];
            $record->income_description = $fields[ 'income_description' ] ?? null;

            $record->base_amount = $base;
            $record->gross_amount = $gross > 0 ? $this->round( $gross ) : $base;
            $record->rate = $rate;
            $record->tax_amount = $tax;
            $record->paid_on = $paidOn->toDateString();

            $record->transaction_id = $fields[ 'transaction_id' ] ?? null;
            $record->status = WithholdingTax::STATUS_ISSUED;
            $record->author_id = $authorId ?? auth()->id();
            $record->save();

            return $record;
        } );
    }

    /**
     * @throws NotAllowedException
     */
    protected function assertCanIssue( array $fields, string $payeeType, float $rate, float $base ): void
    {
        /**
         * Only a registered business is a withholding agent. A shop that is not one has no
         * standing to keep back part of a payment, and a certificate it issued would be
         * worthless to the payee.
         */
        if ( ! $this->isWithholdingAgent() ) {
            throw new NotAllowedException(
                __( 'ยังออกหนังสือรับรองการหักภาษี ณ ที่จ่ายไม่ได้ เพราะร้านยังไม่ได้ตั้งสถานะเป็นผู้มีหน้าที่หักภาษี ณ ที่จ่าย หรือเลขประจำตัวผู้เสียภาษีของร้านยังไม่ถูกต้อง — ตั้งค่าได้ที่ ตั้งค่า → ภาษีไทย → ภาษีหัก ณ ที่จ่าย' )
            );
        }

        if ( ! in_array( $payeeType, [ WithholdingTax::PAYEE_INDIVIDUAL, WithholdingTax::PAYEE_JURISTIC ], true ) ) {
            throw new NotAllowedException( sprintf( __( 'ประเภทผู้รับเงินไม่ถูกต้อง: %s' ), $payeeType ) );
        }

        if ( ! $this->isValidRate( $rate ) ) {
            throw new NotAllowedException(
                __( 'อัตราภาษีหัก ณ ที่จ่ายต้องเป็น 1%, 2%, 3% หรือ 5% เท่านั้น' )
            );
        }

        if ( empty( trim( (string) ( $fields[ 'payee_name' ] ?? '' ) ) ) ) {
            throw new NotAllowedException( __( 'ต้องระบุชื่อผู้รับเงิน' ) );
        }

        /**
         * The Revenue Department matches a filing to a taxpayer by this number. A wrong or
         * missing one is not a cosmetic problem — the withholding cannot be credited to the
         * payee, and they will be taxed again on income already taxed.
         */
        if ( ! $this->vatService->isValidTaxpayerId( $fields[ 'payee_tax_id' ] ?? null ) ) {
            throw new NotAllowedException(
                __( 'เลขประจำตัวผู้เสียภาษีของผู้รับเงินไม่ถูกต้อง ต้องเป็นตัวเลข 13 หลักและผ่านการตรวจเลขหลักสุดท้าย' )
            );
        }

        if ( ! array_key_exists( (string) ( $fields[ 'income_type' ] ?? '' ), $this->incomeTypes() ) ) {
            throw new NotAllowedException( __( 'ต้องเลือกประเภทเงินได้ตามมาตรา 40 ให้ตรงกับที่จะกรอกในแบบ ภ.ง.ด.' ) );
        }

        if ( $base <= 0 ) {
            throw new NotAllowedException( __( 'ยอดเงินก่อนภาษีมูลค่าเพิ่มต้องมากกว่าศูนย์' ) );
        }
    }

    /**
     * Cancels a certificate. Nothing is deleted and no number is reused.
     *
     * @throws NotAllowedException
     */
    public function cancel( WithholdingTax $record, string $reason ): WithholdingTax
    {
        if ( $record->isCancelled() ) {
            throw new NotAllowedException( sprintf( __( 'หนังสือรับรองเลขที่ %s ถูกยกเลิกไปแล้ว' ), $record->number ) );
        }

        if ( trim( $reason ) === '' ) {
            throw new NotAllowedException( __( 'ต้องระบุเหตุผลในการยกเลิกหนังสือรับรองการหักภาษี ณ ที่จ่าย' ) );
        }

        $record->status = WithholdingTax::STATUS_CANCELLED;
        $record->cancellation_reason = $reason;
        $record->cancelled_at = ns()->date->getNow()->toDateTimeString();
        $record->save();

        return $record;
    }

    /**
     * The monthly return summary — ภ.ง.ด.3 or ภ.ง.ด.53 for a period.
     *
     * Grouped by payee and by income type, because that is how both forms are laid out:
     * one line per payee per category, with the count of payments behind it.
     *
     * @return array{ form: string, form_label: string, period: array, payees: array, total_base: float, total_tax: float, count: int, notes: array }
     */
    public function getPndSummary( string $form, ?string $startDate = null, ?string $endDate = null ): array
    {
        $startDate = $startDate ?: ns()->date->getNow()->startOfMonth()->toDateString();
        $endDate = $endDate ?: ns()->date->getNow()->endOfMonth()->toDateString();

        /**
         * Cancelled certificates are excluded: a cancelled withholding was not remitted, so
         * reporting it would overstate what the shop owes.
         */
        $records = WithholdingTax::issued()
            ->form( $form )
            ->paidBetween( $startDate, $endDate )
            ->orderBy( 'paid_on', 'asc' )
            ->orderBy( 'id', 'asc' )
            ->get();

        $payees = $records
            ->groupBy( fn( WithholdingTax $record ) => $record->payee_tax_id . '|' . $record->income_type . '|' . $record->rate )
            ->map( function ( $group ) {
                /**
                 * @var WithholdingTax
                 */
                $first = $group->first();

                return [
                    'payee_name' => $first->payee_name,
                    'payee_tax_id' => $first->payee_tax_id,
                    'payee_branch_code' => $first->payee_branch_code,
                    'income_type' => $first->income_type,
                    'rate' => (float) $first->rate,
                    'count' => $group->count(),
                    'base_amount' => $this->round( $group->sum( fn( WithholdingTax $record ) => (float) $record->base_amount ) ),
                    'tax_amount' => $this->round( $group->sum( fn( WithholdingTax $record ) => (float) $record->tax_amount ) ),
                    'certificates' => $group->map( fn( WithholdingTax $record ) => [
                        'number' => $record->number,
                        'paid_on' => $record->paid_on?->toDateString(),
                        'base_amount' => (float) $record->base_amount,
                        'tax_amount' => (float) $record->tax_amount,
                    ] )->values()->toArray(),
                ];
            } )
            ->values()
            ->toArray();

        $dueDate = Carbon::parse( $endDate )->addMonthNoOverflow()->startOfMonth()->addDays( 6 );

        return [
            'form' => $form,
            'form_label' => $form === WithholdingTax::FORM_PND3 ? __( 'ภ.ง.ด.3' ) : __( 'ภ.ง.ด.53' ),
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'due_date' => $dueDate->toDateString(),
            ],
            'payees' => $payees,
            'total_base' => $this->round( $records->sum( fn( WithholdingTax $record ) => (float) $record->base_amount ) ),
            'total_tax' => $this->round( $records->sum( fn( WithholdingTax $record ) => (float) $record->tax_amount ) ),
            'count' => $records->count(),

            /**
             * Stated in the payload rather than left for the bookkeeper to remember. The
             * deadline is a hard one and a late filing carries a surcharge.
             */
            'notes' => [
                sprintf(
                    __( 'ต้องยื่น %s และนำส่งภาษีที่หักไว้ภายในวันที่ 7 ของเดือนถัดไป (กำหนดชำระ %s) ยื่นผ่านอินเทอร์เน็ตได้ถึงวันที่ 15' ),
                    $form === WithholdingTax::FORM_PND3 ? 'ภ.ง.ด.3' : 'ภ.ง.ด.53',
                    $dueDate->toDateString()
                ),
                __( 'ยอดที่แสดงคิดจากฐานก่อนภาษีมูลค่าเพิ่ม และตัดหนังสือรับรองที่ยกเลิกแล้วออก' ),
                $form === WithholdingTax::FORM_PND3
                    ? __( 'แบบนี้ใช้กับผู้รับเงินที่เป็นบุคคลธรรมดา ถ้าผู้รับเงินเป็นนิติบุคคลต้องยื่น ภ.ง.ด.53 แยกอีกฉบับ' )
                    : __( 'แบบนี้ใช้กับผู้รับเงินที่เป็นนิติบุคคล ถ้าผู้รับเงินเป็นบุคคลธรรมดาต้องยื่น ภ.ง.ด.3 แยกอีกฉบับ' ),
            ],
        ];
    }

    /**
     * Whether the shop is set up to withhold at all.
     */
    public function isWithholdingAgent(): bool
    {
        return ns()->option->get( 'ns_thai_wht_enabled', 'no' ) === 'yes'
            && $this->vatService->isValidTaxpayerId( $this->vatService->taxpayerId() );
    }

    /**
     * The series a certificate issued on the provided date belongs to.
     */
    public function currentScope( Carbon|string|null $date = null ): string
    {
        $prefix = trim( (string) ns()->option->get( 'ns_thai_wht_prefix', 'WHT' ) );
        $prefix = $prefix !== '' ? $prefix : 'WHT';

        $date = $date === null
            ? ns()->date->getNow()
            : ( $date instanceof Carbon ? $date : Carbon::parse( $date ) );

        return $prefix . '-' . $date->format( 'Y' );
    }

    /**
     * The number as printed: series then a six digit running number.
     */
    public function formatNumber( string $scope, int $number ): string
    {
        return $scope . '-' . str_pad( (string) $number, 6, '0', STR_PAD_LEFT );
    }

    /**
     * Allocates the next number in a series, in its own transaction.
     *
     * The public entry point, for callers that persist the record themselves — the CRUD
     * numbers an entry once the row exists, so that a save rejected by validation cannot
     * consume a number and leave a gap in the series.
     */
    public function allocateNumberFor( string $scope ): int
    {
        return DB::transaction( fn () => $this->allocateNumber( $scope ) );
    }

    /**
     * Allocates the next number in a series.
     *
     * The row is locked for the duration of the surrounding transaction, so two users
     * issuing at once queue instead of both reading the same last_number. The unique index
     * on (sequence_scope, sequence_number) is the second line of defence.
     */
    protected function allocateNumber( string $scope ): int
    {
        $sequence = WithholdingTaxSequence::where( 'scope', $scope )->lockForUpdate()->first();

        if ( ! $sequence instanceof WithholdingTaxSequence ) {
            $sequence = new WithholdingTaxSequence;
            $sequence->scope = $scope;
            $sequence->last_number = (int) ns()->option->get( 'ns_thai_wht_start_number', 0 );
        }

        $sequence->last_number = $sequence->last_number + 1;
        $sequence->save();

        return $sequence->last_number;
    }

    /**
     * Strips formatting and restores leading zeros a numeric cast would have eaten.
     */
    protected function normalizeTaxId( ?string $taxId ): string
    {
        $digits = preg_replace( '/\D/', '', (string) $taxId );

        return str_pad( $digits, 13, '0', STR_PAD_LEFT );
    }

    protected function normalizeBranchCode( ?string $branchCode ): string
    {
        $digits = preg_replace( '/\D/', '', (string) $branchCode );

        return $digits === '' ? ThaiVatService::HEAD_OFFICE_BRANCH_CODE : str_pad( $digits, 5, '0', STR_PAD_LEFT );
    }

    protected function round( float $value ): float
    {
        $precision = (int) ns()->option->get( 'ns_currency_precision', 2 );

        return round( $value, $precision >= 0 ? $precision : 2 );
    }
}
