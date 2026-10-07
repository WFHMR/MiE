<?php
use App\Services\ThaiVatService;

/**
 * ใบกำกับภาษีอย่างย่อ — Revenue Code Section 86/6.
 *
 * Printed on the same 58mm thermal roll as the receipt, because it is handed to a
 * walk-in customer at the counter. It carries no buyer details by design: the counter
 * does not ask for an address, and a buyer who needs to claim the VAT asks for the full
 * form instead, which prints on A4 through _tax_invoice_a4.
 *
 * The document prints from the invoice's own frozen snapshot, never from the live order
 * or the current settings. A tax invoice reprinted a year later has to show the seller
 * address, the VAT rate and the amounts that were on it when the customer received it —
 * the order may since have been edited and the shop may have moved.
 */
$lines = collect( $invoice->lines ?? [] );

$statusLabels = [
    ThaiVatService::STATUS_EXEMPT => 'ยกเว้น VAT',
    ThaiVatService::STATUS_ZERO_RATED => 'VAT 0%',
];

$footerMessage = ns()->option->get( 'ns_thai_tax_invoice_footer', '' );
$rateLabel = rtrim( rtrim( number_format( (float) $invoice->vat_rate, 2, '.', '' ), '0' ), '.' );
?>
<div class="w-full h-full">
    <div style="width: 302px; font-family: 'Courier New', monospace; background: #fff; margin: 0 auto; padding: 24px 16px; font-size: 11px; color: #000;">

        {{-- The document title is required wording. A reader must be able to tell an
             abbreviated invoice from a full one without reading the rest. --}}
        <div style="text-align: center; font-weight: bold; font-size: 14px; margin-bottom: 8px;">
            {{ $invoice->document_title }}
        </div>

        @if ( $invoice->isCancelled() )
        <div style="text-align: center; border: 2px solid #000; padding: 4px; margin-bottom: 8px; font-weight: bold;">
            ยกเลิกแล้ว / CANCELLED
        </div>
        @endif

        {{-- Seller: name, registered address, taxpayer number and branch are all
             mandatory under section 86/6. --}}
        <div style="text-align: center; line-height: 1.6; margin-bottom: 8px;">
            <div style="font-weight: bold; font-size: 13px;">{{ $invoice->seller_name }}</div>
            @if ( ! empty( $invoice->seller_address ) )
            <div style="white-space: pre-line; font-size: 10px;">{{ $invoice->seller_address }}</div>
            @endif
            <div style="font-size: 10px;">
                เลขประจำตัวผู้เสียภาษี: {{ $invoice->seller_tax_id }}
            </div>
            <div style="font-size: 10px;">
                สาขา: {{ $invoice->seller_branch_code }}{{ $invoice->seller_branch_code === ThaiVatService::HEAD_OFFICE_BRANCH_CODE ? ' (สำนักงานใหญ่)' : '' }}
            </div>
        </div>

        <div style="border-top: 1px solid #000; margin: 8px 0;"></div>

        {{-- Document identity. The number is the invoice's own running number, not the
             order code: the order code resets daily and can skip values. --}}
        <div style="display: flex; justify-content: space-between; padding: 2px 0;">
            <span>เลขที่:</span>
            <span style="font-weight: bold;">{{ $invoice->number }}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 2px 0;">
            <span>วันที่:</span>
            <span>{{ ns()->date->getFormatted( $invoice->issued_at ) }}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 2px 0;">
            <span>อ้างอิงบิล:</span>
            <span>{{ $order->code }}</span>
        </div>

        <div style="border-top: 1px solid #000; margin: 8px 0;"></div>

        {{-- Line items. Amounts are gross, which is why the statement below is required. --}}
        <div style="font-weight: bold; display: flex; justify-content: space-between; padding-bottom: 3px; border-bottom: 1px solid #666;">
            <span style="flex: 2;">รายการ</span>
            <span style="flex: 0.7; text-align: center;">จำนวน</span>
            <span style="flex: 1.1; text-align: right;">ราคา</span>
        </div>

        @foreach ( $lines as $line )
        <div style="padding: 3px 0; display: flex; justify-content: space-between; border-bottom: 1px dotted #ccc;">
            <span style="flex: 2;">
                {{ $line[ 'name' ] }}
                @if ( isset( $statusLabels[ $line[ 'vat_status' ] ] ) )
                <span style="font-size: 9px;">({{ $statusLabels[ $line[ 'vat_status' ] ] }})</span>
                @endif
            </span>
            <span style="flex: 0.7; text-align: center;">{{ rtrim( rtrim( number_format( (float) $line[ 'quantity' ], 2, '.', '' ), '0' ), '.' ) }}</span>
            <span style="flex: 1.1; text-align: right;">{{ number_format( (float) $line[ 'gross' ], 2 ) }}</span>
        </div>
        @endforeach

        <div style="border-top: 1px solid #000; margin: 8px 0;"></div>

        {{-- Totals. The VAT has to be shown as its own amount, separate from the value
             of the goods — that separation is the whole point of the document. --}}
        <div>
            @if ( (float) $invoice->total_exempt > 0 )
            <div style="display: flex; justify-content: space-between; padding: 2px 0;">
                <span>ยอดยกเว้น VAT:</span>
                <span>{{ number_format( (float) $invoice->total_exempt, 2 ) }}</span>
            </div>
            @endif

            @if ( (float) $invoice->total_zero_rated > 0 )
            <div style="display: flex; justify-content: space-between; padding: 2px 0;">
                <span>ยอดอัตราร้อยละ 0:</span>
                <span>{{ number_format( (float) $invoice->total_zero_rated, 2 ) }}</span>
            </div>
            @endif

            <div style="display: flex; justify-content: space-between; padding: 2px 0;">
                <span>มูลค่าสินค้า (ก่อน VAT):</span>
                <span>{{ number_format( (float) $invoice->total_net, 2 ) }}</span>
            </div>

            <div style="display: flex; justify-content: space-between; padding: 2px 0;">
                <span>ภาษีมูลค่าเพิ่ม {{ $rateLabel }}%:</span>
                <span>{{ number_format( (float) $invoice->total_vat, 2 ) }}</span>
            </div>

            <div style="display: flex; justify-content: space-between; padding: 4px 0; font-weight: bold; font-size: 13px; border-top: 1px solid #000; border-bottom: 1px solid #000; margin: 4px 0;">
                <span>รวมทั้งสิ้น:</span>
                <span>{{ number_format( (float) $invoice->total_gross, 2 ) }}</span>
            </div>
        </div>

        {{-- Section 86/6 requires an abbreviated invoice to state that the prices shown
             already contain the VAT, because it prints gross line amounts. --}}
        <div style="text-align: center; font-size: 10px; margin-top: 6px;">
            ราคารวมภาษีมูลค่าเพิ่มแล้ว
        </div>

        <div style="margin-top: 8px;">
            @foreach ( $order->payments as $payment )
            <div style="display: flex; justify-content: space-between; padding: 2px 0;">
                <span>ชำระโดย: {{ $paymentTypes[ $payment[ 'identifier' ] ] ?? $payment[ 'identifier' ] }}</span>
                <span>{{ number_format( (float) $payment[ 'value' ], 2 ) }}</span>
            </div>
            @endforeach
        </div>

        @if ( ! empty( $footerMessage ) )
        <div style="border-top: 1px dashed #000; margin: 10px 0;"></div>
        <div style="text-align: center; white-space: pre-line; font-size: 10px;">{{ $footerMessage }}</div>
        @endif

        <div style="text-align: center; font-size: 9px; color: #666; margin-top: 8px;">
            ออกโดย {{ $invoice->user->username ?? '-' }}
        </div>
    </div>
</div>
