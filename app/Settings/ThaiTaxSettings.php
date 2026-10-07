<?php

namespace App\Settings;

use App\Classes\Hook;
use App\Services\SettingsPage;
use App\Services\ThaiVatService;
use App\Services\ThaiWithholdingTaxService;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;

/**
 * Thai tax settings — the registration details and the VAT rate periods.
 *
 * The rate is not stored as a plain option. Options have no history, and a tax
 * invoice reprinted after a rate change has to show the rate that was actually
 * charged. Saving this page therefore writes a dated period into
 * nexopos_vat_rates, and the options only mirror the latest declaration so the
 * form can be repopulated.
 */
class ThaiTaxSettings extends SettingsPage
{
    const IDENTIFIER = 'thai-tax';

    const AUTOLOAD = true;

    public $form;

    public function __construct()
    {
        $this->form = [
            'tabs' => Hook::filter( 'ns-thai-tax-settings-tabs', [
                'identity' => include ( dirname( __FILE__ ) . '/thai-tax/identity.php' ),
                'vat' => include ( dirname( __FILE__ ) . '/thai-tax/vat.php' ),
                'invoice' => include ( dirname( __FILE__ ) . '/thai-tax/invoice.php' ),
                'withholding' => include ( dirname( __FILE__ ) . '/thai-tax/withholding.php' ),
            ] ),
            'title' => __( 'ภาษีไทย' ),
            'description' => __( 'ตั้งค่าการจดทะเบียนภาษีมูลค่าเพิ่มและอัตราภาษีที่มีผลตามช่วงเวลา' ),
        ];
    }

    /**
     * Adds the checks that cannot be expressed as a rule string.
     *
     * The field declarations stay serialisable for the frontend, and the closure
     * based rules only ever run on the POST. Declaring the taxpayer id checksum in
     * the field itself would ship a Closure to the browser as an empty object.
     */
    public function validateForm( Request $request ): array
    {
        $rules = parent::validateForm( $request );

        $rules[ 'identity.ns_thai_tax_id' ] = [
            'nullable',
            'string',
            function ( string $attribute, mixed $value, Closure $fail ) {
                if ( empty( $value ) ) {
                    return;
                }

                if ( ! app()->make( ThaiVatService::class )->isValidTaxpayerId( (string) $value ) ) {
                    $fail( __( 'เลขประจำตัวผู้เสียภาษีไม่ถูกต้อง ต้องเป็นตัวเลข 13 หลักและผ่านการตรวจเลขหลักสุดท้าย' ) );
                }
            },
        ];

        $rules[ 'identity.ns_thai_tax_branch_code' ] = [ 'nullable', 'regex:/^\d{5}$/' ];
        $rules[ 'vat.ns_thai_vat_effective_to' ] = [ 'nullable', 'date' ];

        /**
         * A shop cannot be flagged as registered without a taxpayer number, because
         * every downstream document decision reads that flag and would produce an
         * unusable tax invoice.
         */
        $rules[ 'identity.ns_thai_vat_registered' ] = [
            'required',
            'in:yes,no',
            function ( string $attribute, mixed $value, Closure $fail ) use ( $request ) {
                if ( $value !== 'yes' ) {
                    return;
                }

                if ( ! app()->make( ThaiVatService::class )->isValidTaxpayerId( (string) $request->input( 'identity.ns_thai_tax_id' ) ) ) {
                    $fail( __( 'ต้องกรอกเลขประจำตัวผู้เสียภาษี 13 หลักที่ถูกต้องก่อนตั้งสถานะเป็นจดทะเบียนแล้ว' ) );
                }
            },
        ];

        /**
         * A withholding agent's own taxpayer number is printed on every certificate it
         * issues, in the payer position. Flagging the shop as an agent without one produces
         * documents the payee cannot use.
         */
        $rules[ 'withholding.ns_thai_wht_enabled' ] = [
            'required',
            'in:yes,no',
            function ( string $attribute, mixed $value, Closure $fail ) use ( $request ) {
                if ( $value !== 'yes' ) {
                    return;
                }

                if ( ! app()->make( ThaiVatService::class )->isValidTaxpayerId( (string) $request->input( 'identity.ns_thai_tax_id' ) ) ) {
                    $fail( __( 'ต้องกรอกเลขประจำตัวผู้เสียภาษี 13 หลักที่ถูกต้องก่อนตั้งสถานะเป็นผู้มีหน้าที่หักภาษี ณ ที่จ่าย' ) );
                }
            },
        ];

        /**
         * Only four rates exist in the orders that create the obligation. A free-text rate
         * would let a shop withhold at a percentage that no certificate can justify.
         */
        $rules[ 'withholding.ns_thai_wht_default_rate' ] = [
            'required',
            function ( string $attribute, mixed $value, Closure $fail ) {
                if ( ! app()->make( ThaiWithholdingTaxService::class )->isValidRate( $value ) ) {
                    $fail( __( 'อัตราภาษีหัก ณ ที่จ่ายต้องเป็น 1%, 2%, 3% หรือ 5% เท่านั้น' ) );
                }
            },
        ];

        return $rules;
    }

    /**
     * Persists the options, then reflects the declared rate into a dated period.
     *
     * @return array
     */
    public function saveForm( Request $request )
    {
        $response = parent::saveForm( $request );

        $this->syncVatRatePeriod( $request );

        return $response;
    }

    /**
     * Opens a new rate period when the declaration actually changed.
     *
     * "effective_to" is read from the raw request rather than from the flattened
     * option data, because SettingsPage drops empty values when flattening: an
     * emptied end date would otherwise keep its previous value and silently expire
     * the current rate.
     */
    protected function syncVatRatePeriod( Request $request ): void
    {
        /**
         * @var ThaiVatService
         */
        $vatService = app()->make( ThaiVatService::class );

        $rate = $request->input( 'vat.ns_thai_vat_rate' );
        $from = $request->input( 'vat.ns_thai_vat_effective_from' );
        $to = $request->input( 'vat.ns_thai_vat_effective_to' );
        $reference = $request->input( 'vat.ns_thai_vat_legal_reference' );

        if ( $rate === null || $from === null || $from === '' ) {
            return;
        }

        $rate = (float) $rate;
        $from = Carbon::parse( $from )->startOfDay();
        $to = empty( $to ) ? null : Carbon::parse( $to )->startOfDay();

        /**
         * The empty string has to be written back explicitly. SettingsPage skips it
         * during flattening, so without this the option would still hold the old end
         * date while the rate table has none.
         */
        ns()->option->set( 'ns_thai_vat_effective_to', $to?->toDateString() ?? '' );

        $current = $vatService->rateRecordOn( $from );

        $unchanged = $current !== null
            && (float) $current->rate === $rate
            && $current->effective_from?->isSameDay( $from )
            && (
                ( $current->effective_to === null && $to === null )
                || ( $current->effective_to !== null && $to !== null && $current->effective_to->isSameDay( $to ) )
            );

        /**
         * Saving the page without touching the rate must not pile up identical
         * periods. Only the legal reference is refreshed in that case.
         */
        if ( $unchanged ) {
            if ( $current->legal_reference !== $reference ) {
                $current->legal_reference = $reference;
                $current->save();
            }

            return;
        }

        $vatService->openRatePeriod(
            rate: $rate,
            from: $from,
            to: $to,
            legalReference: $reference,
        );
    }
}
