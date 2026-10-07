<?php
use App\Models\WithholdingTax;

/**
 * หนังสือรับรองการหักภาษี ณ ที่จ่าย — the certificate the payee files with their own
 * return to claim the tax already withheld from them.
 *
 * Printed entirely from the record's own snapshot. The payee's name, taxpayer number and
 * address were copied onto the record when the payment was made, so a landlord who later
 * moves does not change a certificate already handed over and already reported on a
 * monthly return.
 *
 * The document has to carry, per section 50 bis: both parties' taxpayer numbers, the
 * section 40 income category, the amount paid, the tax withheld, and a statement of the
 * ground on which the tax was withheld.
 */
$incomeTypes = app()->make( App\Services\ThaiWithholdingTaxService::class )->incomeTypes();
$footerMessage = ns()->option->get( 'ns_thai_wht_footer', '' );
$rateLabel = rtrim( rtrim( number_format( (float) $record->rate, 2, '.', '' ), '0' ), '.' );
?>
<div class="w-full h-full">
    <div style="width: 720px; font-family: 'Courier New', monospace; background: #fff; margin: 0 auto; padding: 28px 24px; font-size: 13px; color: #000;">

        <div style="text-align: center; font-weight: bold; font-size: 18px; margin-bottom: 2px;">
            หนังสือรับรองการหักภาษี ณ ที่จ่าย
        </div>
        <div style="text-align: center; font-size: 11px; margin-bottom: 10px;">
            ตามมาตรา 50 ทวิ แห่งประมวลรัษฎากร
        </div>

        @if ( $record->isCancelled() )
        <div style="text-align: center; border: 2px solid #000; padding: 4px; margin-bottom: 10px; font-weight: bold;">
            ยกเลิกแล้ว / CANCELLED
        </div>
        @endif

        <div style="display: flex; justify-content: space-between; border: 1px solid #000; padding: 6px 8px; margin-bottom: 10px;">
            <span>เลขที่: <strong>{{ $record->number }}</strong></span>
            <span>แบบที่ยื่น: <strong>{{ $record->form_label }}</strong></span>
        </div>

        {{-- The payer. Its taxpayer number is what the Revenue Department matches the
             remittance against, so it is mandatory on the document. --}}
        <div style="border: 1px solid #000; padding: 8px; margin-bottom: 8px; line-height: 1.7;">
            <div style="font-weight: bold; margin-bottom: 2px;">ผู้จ่ายเงิน (ผู้มีหน้าที่หักภาษี ณ ที่จ่าย)</div>
            <div>ชื่อ: {{ $payer[ 'name' ] }}</div>
            @if ( ! empty( $payer[ 'address' ] ) )
            <div style="white-space: pre-line;">ที่อยู่: {{ $payer[ 'address' ] }}</div>
            @endif
            <div>เลขประจำตัวผู้เสียภาษี: {{ $payer[ 'tax_id' ] }}</div>
            <div>สาขา: {{ $payer[ 'branch_code' ] }}{{ $payer[ 'branch_code' ] === '00000' ? ' (สำนักงานใหญ่)' : '' }}</div>
        </div>

        {{-- The payee. A wrong taxpayer number here means the withholding cannot be
             credited to them and they get taxed twice on the same income. --}}
        <div style="border: 1px solid #000; padding: 8px; margin-bottom: 8px; line-height: 1.7;">
            <div style="font-weight: bold; margin-bottom: 2px;">ผู้ถูกหักภาษี ณ ที่จ่าย ({{ $record->payee_type_label }})</div>
            <div>ชื่อ: {{ $record->payee_name }}</div>
            @if ( ! empty( $record->payee_address ) )
            <div style="white-space: pre-line;">ที่อยู่: {{ $record->payee_address }}</div>
            @endif
            <div>เลขประจำตัวผู้เสียภาษี: {{ $record->payee_tax_id }}</div>
            <div>สาขา: {{ $record->payee_branch_code }}{{ $record->payee_branch_code === '00000' ? ' (สำนักงานใหญ่)' : '' }}</div>
        </div>

        {{-- The amounts. The base is the figure before VAT: the rate applies to that, not
             to the invoice total, and printing both makes the distinction checkable. --}}
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
            <thead>
                <tr>
                    <th style="border: 1px solid #000; padding: 5px; text-align: left;">ประเภทเงินได้พึงประเมินที่จ่าย</th>
                    <th style="border: 1px solid #000; padding: 5px; text-align: center; width: 90px;">วันที่จ่าย</th>
                    <th style="border: 1px solid #000; padding: 5px; text-align: right; width: 120px;">จำนวนเงินที่จ่าย<br><span style="font-weight: normal; font-size: 10px;">(ก่อน VAT)</span></th>
                    <th style="border: 1px solid #000; padding: 5px; text-align: right; width: 120px;">ภาษีที่หักและนำส่ง</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="border: 1px solid #000; padding: 5px;">
                        {{ $incomeTypes[ $record->income_type ] ?? $record->income_type }}
                        @if ( ! empty( $record->income_description ) )
                        <div style="font-size: 11px;">{{ $record->income_description }}</div>
                        @endif
                    </td>
                    <td style="border: 1px solid #000; padding: 5px; text-align: center;">{{ $record->paid_on->toDateString() }}</td>
                    <td style="border: 1px solid #000; padding: 5px; text-align: right;">{{ number_format( (float) $record->base_amount, 2 ) }}</td>
                    <td style="border: 1px solid #000; padding: 5px; text-align: right;">{{ number_format( (float) $record->tax_amount, 2 ) }}</td>
                </tr>
                <tr>
                    <td colspan="3" style="border: 1px solid #000; padding: 5px; text-align: right; font-weight: bold;">รวมภาษีที่หักและนำส่ง (อัตรา {{ $rateLabel }}%)</td>
                    <td style="border: 1px solid #000; padding: 5px; text-align: right; font-weight: bold;">{{ number_format( (float) $record->tax_amount, 2 ) }}</td>
                </tr>
            </tbody>
        </table>

        @if ( (float) $record->gross_amount > (float) $record->base_amount )
        {{-- Shown so the bookkeeper can tie this back to the supplier's invoice, which
             states the VAT inclusive total. It is not the figure the rate applied to. --}}
        <div style="font-size: 11px; margin-bottom: 8px;">
            ยอดตามใบแจ้งหนี้ (รวมภาษีมูลค่าเพิ่ม): {{ number_format( (float) $record->gross_amount, 2 ) }} —
            ภาษีมูลค่าเพิ่มที่ถอดออก: {{ number_format( (float) $record->gross_amount - (float) $record->base_amount, 2 ) }}
        </div>
        @endif

        <div style="border: 1px solid #000; padding: 8px; margin-bottom: 12px; font-size: 12px;">
            <div style="font-weight: bold;">เงื่อนไขการหักภาษี</div>
            <div>หักภาษี ณ ที่จ่าย และนำส่งกรมสรรพากรตามแบบ {{ $record->form_label }}</div>
            <div style="font-size: 11px; margin-top: 3px;">
                {{ $record->form === WithholdingTax::FORM_PND3 ? 'ผู้รับเงินเป็นบุคคลธรรมดา' : 'ผู้รับเงินเป็นนิติบุคคล' }} —
                กำหนดนำส่งภายในวันที่ 7 ของเดือนถัดจากเดือนที่จ่าย
            </div>
        </div>

        <div style="text-align: right; margin-top: 28px;">
            <div style="display: inline-block; text-align: center; width: 260px;">
                <div style="border-top: 1px solid #000; padding-top: 4px;">ผู้จ่ายเงิน / ผู้มีอำนาจลงนาม</div>
                <div style="font-size: 11px; margin-top: 12px;">วันที่ {{ $record->paid_on->toDateString() }}</div>
            </div>
        </div>

        @if ( ! empty( $footerMessage ) )
        <div style="border-top: 1px dashed #000; margin: 12px 0;"></div>
        <div style="white-space: pre-line; font-size: 11px;">{{ $footerMessage }}</div>
        @endif

        <div style="text-align: center; font-size: 10px; color: #666; margin-top: 10px;">
            ออกโดย {{ $record->user->username ?? '-' }}
        </div>
    </div>
</div>
@includeWhen( request()->query( 'autoprint' ) === 'true', '/pages/dashboard/orders/templates/_autoprint' )
