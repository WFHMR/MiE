<?php

namespace App\Services;

use App\Models\VatRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Thai VAT engine.
 *
 * The shop sells with VAT included in the shelf price, which is how Thai retail
 * prices are displayed, so every computation here derives the net amount and the
 * tax downwards from a gross amount rather than adding tax on top.
 *
 * Three classifications exist, and they are not interchangeable on a return:
 *
 *  - standard    taxed at the rate in force on the day of the sale
 *  - exempt      outside the VAT system under Section 81 of the Revenue Code
 *                (unprocessed agricultural produce, fresh meat, raw rice...).
 *                An exempt sale carries no output tax and is reported on its own
 *                line of the PP30 return, so it must not be merged with a 0% sale
 *  - zero_rated  inside the VAT system but taxed at 0% (exports). Output tax is
 *                zero, yet the sale still counts as a taxable supply
 *
 * The distinction matters because it changes the return, not the receipt total.
 */
class ThaiVatService
{
    /**
     * Taxed at the prevailing rate.
     */
    const STATUS_STANDARD = 'standard';

    /**
     * Outside the VAT system — Revenue Code Section 81.
     */
    const STATUS_EXEMPT = 'exempt';

    /**
     * Taxable supply charged at 0%.
     */
    const STATUS_ZERO_RATED = 'zero_rated';

    /**
     * Fallback rate used only when the shop has declared nothing at all.
     *
     * 7% is the reduced rate granted by Royal Decree; the statutory rate in
     * Section 80 is 10%. This constant is a starting value for a fresh install,
     * never a substitute for the rate table.
     */
    const DEFAULT_RATE = 7.0;

    /**
     * Head office branch code on a Thai tax invoice.
     */
    const HEAD_OFFICE_BRANCH_CODE = '00000';

    public function __construct( protected CurrencyService $currency ) {}

    /**
     * The three classifications, labelled for the dashboard.
     *
     * @return array<string,string>
     */
    public function statuses(): array
    {
        return [
            self::STATUS_STANDARD => __( 'เสีย VAT (อัตราปกติ)' ),
            self::STATUS_EXEMPT => __( 'ยกเว้น VAT (มาตรา 81)' ),
            self::STATUS_ZERO_RATED => __( 'อัตราร้อยละ 0 (ส่งออก)' ),
        ];
    }

    /**
     * Whether the provided value is one of the three known classifications.
     */
    public function isValidStatus( ?string $status ): bool
    {
        return in_array( $status, array_keys( $this->statuses() ), true );
    }

    /**
     * Normalises an untrusted classification, falling back to the shop default.
     */
    public function normalizeStatus( ?string $status ): string
    {
        if ( $this->isValidStatus( $status ) ) {
            return $status;
        }

        $default = ns()->option->get( 'ns_thai_vat_default_status', self::STATUS_STANDARD );

        return $this->isValidStatus( $default ) ? $default : self::STATUS_STANDARD;
    }

    /**
     * The rate record in force on the provided date, latest period first.
     *
     * Ordering by "effective_from" descending resolves overlapping periods in
     * favour of the most recently opened one, so a data entry mistake degrades to
     * "the newest rate wins" instead of an arbitrary row.
     */
    public function rateRecordOn( Carbon|string|null $date = null ): ?VatRate
    {
        return VatRate::effectiveOn( $date )
            ->orderBy( 'effective_from', 'desc' )
            ->orderBy( 'id', 'desc' )
            ->first();
    }

    /**
     * The percentage in force on the provided date.
     */
    public function rateOn( Carbon|string|null $date = null ): float
    {
        $record = $this->rateRecordOn( $date );

        if ( $record instanceof VatRate ) {
            return (float) $record->rate;
        }

        return (float) ns()->option->get( 'ns_thai_vat_rate', self::DEFAULT_RATE );
    }

    /**
     * The percentage that applies to a classification on a given date.
     *
     * Both exempt and zero rated resolve to 0%. They differ in how the sale is
     * reported, not in how much tax is collected, which is why the classification
     * is stored alongside the rate rather than inferred back from it.
     */
    public function rateFor( ?string $status, Carbon|string|null $date = null ): float
    {
        return $this->normalizeStatus( $status ) === self::STATUS_STANDARD
            ? $this->rateOn( $date )
            : 0.0;
    }

    /**
     * Net amount contained in a VAT inclusive amount.
     *
     * The gross is rounded to the shop's precision first, so that this and
     * vatFromGross() are both derived from the same figure. Deriving the net from
     * the raw gross and the VAT from the rounded one is what lets a satang escape.
     */
    public function netFromGross( float $gross, float $rate ): float
    {
        $gross = $this->round( $gross );

        if ( $rate <= 0 ) {
            return $gross;
        }

        return $this->round(
            ns()->currency->fresh( $gross )
                ->multipliedBy( 100 )
                ->dividedBy( 100 + $rate )
                ->toFloat()
        );
    }

    /**
     * VAT contained in a VAT inclusive amount.
     *
     * Obtained by subtracting the net from the gross, both already rounded to the
     * shop's precision, so net + vat reconstructs the gross exactly for every
     * amount and every precision. Computing the VAT independently from the gross
     * leaves a one satang gap on some amounts, and a tax invoice whose lines do not
     * add up to its total is not a valid document — the Revenue Department reads the
     * total, the buyer claims the VAT line, and the two have to agree.
     */
    public function vatFromGross( float $gross, float $rate ): float
    {
        if ( $rate <= 0 ) {
            return 0.0;
        }

        return $this->round(
            ns()->currency->fresh( $this->round( $gross ) )
                ->subtractBy( $this->netFromGross( $gross, $rate ) )
                ->toFloat()
        );
    }

    /**
     * VAT inclusive amount for a net amount.
     */
    public function grossFromNet( float $net, float $rate ): float
    {
        if ( $rate <= 0 ) {
            return $this->round( $net );
        }

        return $this->round(
            ns()->currency->fresh( $net )
                ->multipliedBy( 100 + $rate )
                ->dividedBy( 100 )
                ->toFloat()
        );
    }

    /**
     * Full split of a VAT inclusive amount for a classification.
     *
     * @return array{ status: string, rate: float, gross: float, net: float, vat: float, taxable: bool }
     */
    public function breakdownFromGross( float $gross, ?string $status = null, Carbon|string|null $date = null ): array
    {
        $status = $this->normalizeStatus( $status );
        $rate = $this->rateFor( $status, $date );

        return [
            'status' => $status,
            'rate' => $rate,
            'gross' => $this->round( $gross ),
            'net' => $this->netFromGross( $gross, $rate ),
            'vat' => $this->vatFromGross( $gross, $rate ),

            /**
             * An exempt supply is not a taxable supply. A zero rated one is, even
             * though its output tax is zero — the PP30 return separates them.
             */
            'taxable' => $status !== self::STATUS_EXEMPT,
        ];
    }

    /**
     * Opens a new rate period and closes the one that is currently open ended.
     *
     * The old period is closed the day before the new one starts so the two never
     * overlap and no day is left without a rate. Sales already recorded keep the
     * rate they were taxed with, because rateOn() resolves by date.
     */
    public function openRatePeriod(
        float $rate,
        Carbon|string $from,
        Carbon|string|null $to = null,
        ?string $label = null,
        ?string $legalReference = null,
        ?int $authorId = null,
    ): VatRate {
        $from = $from instanceof Carbon ? $from->startOfDay() : Carbon::parse( $from )->startOfDay();
        $to = $to === null || $to === '' ? null : ( $to instanceof Carbon ? $to->startOfDay() : Carbon::parse( $to )->startOfDay() );

        if ( $to !== null && $to->lessThan( $from ) ) {
            throw new \InvalidArgumentException( __( 'วันสิ้นสุดของอัตราภาษีต้องไม่มาก่อนวันเริ่มมีผล' ) );
        }

        return DB::transaction( function () use ( $rate, $from, $to, $label, $legalReference, $authorId ) {
            /**
             * A period is identified by the day it starts. Declaring the same start
             * date again is a correction of that period, not an additional one, so the
             * previous row is replaced rather than left to overlap.
             */
            VatRate::whereDate( 'effective_from', $from->toDateString() )->delete();

            /**
             * Any earlier period still running on the new start date is closed the day
             * before, so the two never overlap and no day is left without a rate.
             * Periods that already ended are untouched, and later periods are left
             * alone — reopening an old period must not erase the ones after it.
             */
            VatRate::whereDate( 'effective_from', '<', $from->toDateString() )
                ->where( function ( $query ) use ( $from ) {
                    $query->whereNull( 'effective_to' )
                        ->orWhereDate( 'effective_to', '>=', $from->toDateString() );
                } )
                ->update( [ 'effective_to' => $from->copy()->subDay()->toDateString() ] );

            $vatRate = new VatRate;
            $vatRate->label = $label ?: sprintf( 'VAT %s%%', rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' ) );
            $vatRate->rate = $rate;
            $vatRate->effective_from = $from;
            $vatRate->effective_to = $to;
            $vatRate->legal_reference = $legalReference;
            $vatRate->author_id = $authorId ?? auth()->id();
            $vatRate->save();

            return $vatRate;
        } );
    }

    /**
     * Ensures a rate exists so the till never falls back to a hardcoded constant.
     *
     * Called on a fresh install and by the settings page. Returns the record in
     * force today, creating the reduced 7% period if the table is empty.
     */
    public function ensureRateExists( Carbon|string|null $date = null ): VatRate
    {
        $record = $this->rateRecordOn( $date );

        if ( $record instanceof VatRate ) {
            return $record;
        }

        return $this->openRatePeriod(
            rate: (float) ns()->option->get( 'ns_thai_vat_rate', self::DEFAULT_RATE ),
            from: Carbon::parse( $date ?: now() )->startOfYear()->subYears( 5 ),
            to: null,
            label: sprintf( 'VAT %s%%', self::DEFAULT_RATE ),
            legalReference: __( 'อัตราลดตามพระราชกฤษฎีกา (มาตรา 80 แห่งประมวลรัษฎากรกำหนดอัตรา 10%)' ),
        );
    }

    /**
     * The taxpayer identification number, with its leading zeros restored.
     *
     * Options::decodeOptionValue() casts any digit-only option to an integer, so
     * "0105536001107" comes back as 105536001107 and "00000" as 0. Thai taxpayer
     * numbers and branch codes always carry leading zeros, so reading them raw
     * silently produces an invalid number. Re-padding to the fixed width restores
     * exactly the digits the cast removed.
     */
    public function taxpayerId(): string
    {
        return $this->fixedWidthDigits( ns()->option->get( 'ns_thai_tax_id', '' ), 13 );
    }

    /**
     * The branch code, with its leading zeros restored. Defaults to head office.
     *
     * This is the install wide code, used when the branch a sale was made at carries none of its
     * own. A shop with more than one establishment should fill the code in per branch instead — see
     * normaliseBranchCode(), which the tax invoice uses for that.
     */
    public function branchCode(): string
    {
        return $this->normaliseBranchCode( ns()->option->get( 'ns_thai_tax_branch_code', '' ) );
    }

    /**
     * Puts a branch code into the shape a tax invoice has to show it in.
     *
     * A code is five digits and keeps its leading zeros, so "1" typed into a form has to be printed
     * as 00001. Head office is 00000, which is also what an empty value means.
     */
    public function normaliseBranchCode( ?string $code ): string
    {
        $digits = $this->fixedWidthDigits( (string) $code, 5 );

        return $digits !== '' ? $digits : self::HEAD_OFFICE_BRANCH_CODE;
    }

    /**
     * Whether the shop has declared a VAT registration.
     *
     * Below the THB 1.8M annual turnover threshold a shop is not required to
     * register, and an unregistered shop may not issue a tax invoice at all — only
     * a receipt. Every document decision downstream depends on this flag.
     */
    public function isRegistered(): bool
    {
        return ns()->option->get( 'ns_thai_vat_registered', 'no' ) === 'yes'
            && $this->isValidTaxpayerId( $this->taxpayerId() );
    }

    /**
     * The registration details that a tax invoice has to carry.
     *
     * @return array{ tax_id: string, name: string, address: string, branch_code: string, registered: bool }
     */
    public function taxpayerProfile(): array
    {
        return [
            'tax_id' => $this->taxpayerId(),
            'name' => trim( (string) ns()->option->get( 'ns_thai_tax_registered_name', '' ) ),
            'address' => trim( (string) ns()->option->get( 'ns_thai_tax_registered_address', '' ) ),
            'branch_code' => $this->branchCode(),
            'registered' => $this->isRegistered(),
        ];
    }

    /**
     * A taxpayer number restored to its thirteen digits, for anything reading the option directly.
     *
     * The option layer casts a digit only string to an integer, so `0223554000076` comes back as
     * `223554000076` — twelve digits, and a twelve digit taxpayer number is not a valid one. The
     * value in the database is intact; it is the reading of it that loses the zero. The tax invoice
     * already went through taxpayerId() and was safe, but the receipt read the store option straight
     * and printed the short number on every sale.
     *
     * Returns an empty string for an empty input, so a shop that has entered nothing does not print
     * a row of zeros.
     */
    public function formatTaxpayerId( mixed $value ): string
    {
        return $this->fixedWidthDigits( $value, 13 );
    }

    /**
     * Keeps only digits and pads to the given width from the left.
     *
     * An empty input stays empty rather than becoming a string of zeros, so a shop
     * that has entered nothing is distinguishable from one that entered 00000.
     */
    protected function fixedWidthDigits( mixed $value, int $width ): string
    {
        $digits = preg_replace( '/\D/', '', (string) $value );

        if ( $digits === '' || $digits === null ) {
            return '';
        }

        return strlen( $digits ) < $width
            ? str_pad( $digits, $width, '0', STR_PAD_LEFT )
            : $digits;
    }

    /**
     * Validates a 13 digit Thai taxpayer identification number.
     *
     * The last digit is a checksum over the first twelve, each weighted by its
     * position counting down from 13. A wrong number on a tax invoice makes the
     * document unusable for the buyer's input tax claim, so this is checked at
     * entry rather than at print time.
     */
    public function isValidTaxpayerId( ?string $taxpayerId ): bool
    {
        $digits = preg_replace( '/\D/', '', (string) $taxpayerId );

        if ( strlen( (string) $digits ) !== 13 ) {
            return false;
        }

        $sum = 0;

        for ( $position = 0; $position < 12; $position++ ) {
            $sum += (int) $digits[ $position ] * ( 13 - $position );
        }

        $checksum = ( 11 - ( $sum % 11 ) ) % 10;

        return $checksum === (int) $digits[ 12 ];
    }

    /**
     * Rounds a monetary amount to the precision the shop displays prices with.
     */
    protected function round( float $value ): float
    {
        $precision = (int) ns()->option->get( 'ns_currency_precision', 2 );

        return round( $value, $precision >= 0 ? $precision : 2 );
    }
}
