<?php
use App\Services\ThaiBahtTextService;
use App\Services\ThaiVatService;

/**
 * ใบส่งสินค้า/ใบกำกับภาษี/ใบเสร็จรับเงิน — the A4 full form.
 *
 * Laid out to match the shop's existing printed stationery, because staff and
 * customers read these by position rather than by label: the number sits top right,
 * the buyer block top left, the VAT split bottom right. A correct document in an
 * unfamiliar arrangement still gets queried at the counter.
 *
 * Everything prints from the invoice's own frozen snapshot rather than the live order.
 * A reprint a year later has to reproduce the seller address, the buyer's details, the
 * VAT rate and the amounts that were on it when the customer received it — the order
 * may since have been edited, the customer renamed and the shop moved.
 *
 * Table based rather than flex: this has to paginate in a print engine, and a table row
 * is the one element browsers reliably keep whole across a page break.
 */
$lines = collect( $invoice->lines ?? [] );

$bahtText = app()->make( ThaiBahtTextService::class );

/**
 * The tax column on the paper form. V and N are the shop's existing legend; zero rated
 * supplies get their own mark rather than being folded into N, because an exempt supply
 * is outside the VAT system and a zero rated one is inside it at 0%, and the PP30
 * return reports them on separate lines.
 */
$taxMarks = [
    ThaiVatService::STATUS_STANDARD => 'V',
    ThaiVatService::STATUS_EXEMPT => 'N',
    ThaiVatService::STATUS_ZERO_RATED => '0',
];

$rateLabel = number_format( (float) $invoice->vat_rate, 2 );

$hasExempt = (float) $invoice->total_exempt > 0;
$hasZeroRated = (float) $invoice->total_zero_rated > 0;

/**
 * Assembled here rather than with inline @if directives. Blade only recognises a
 * directive at a non word boundary, so an @endif placed immediately after the "0" in
 * "ร้อยละ 0" is read as literal text and the @if is never closed.
 */
$taxLegend = collect( [
    'V - สินค้าคิดภาษีมูลค่าเพิ่ม',
    'N - สินค้ายกเว้นภาษีมูลค่าเพิ่ม',
] )
    ->when( $hasZeroRated, fn ( $legend ) => $legend->push( '0 - สินค้าอัตราร้อยละ 0' ) )
    ->implode( ', ' );

/**
 * The body is padded to a fixed number of rows so the totals block lands in the same
 * place on every copy. A form whose footer floats up the page is hard to check against
 * a filed copy, and pre-printed stationery has the boxes in fixed positions.
 */
$minimumRows = 14;
$fillerRows = max( 0, $minimumRows - $lines->count() );

$border = '1px solid #000';
$cell = 'border: ' . $border . '; padding: 3px 5px; vertical-align: top;';
?>
<style>
    /**
     * The only @page rule in the application. Declared here rather than in the global
     * stylesheet because it must not affect the thermal receipt, which is 58mm wide and
     * would be padded out to A4 by it.
     *
     * This document is printed through the Blade + window.print() route, which keeps the
     * whole page intact, so a rule declared here reaches the print engine. It would not
     * survive $htmlToPaper, which copies only an element's innerHTML.
     */
    @page {
        size: A4 portrait;
        margin: 10mm 8mm;
    }

    @media print {
        body {
            margin: 0;
            background: #fff;
        }

        /* Repeat the column headings when the item list spills onto a second sheet. */
        .ns-a4-items thead {
            display: table-header-group;
        }

        .ns-a4-items tr {
            page-break-inside: avoid;
        }

        /* The totals and the signatures belong together at the foot of the document. */
        .ns-a4-closing {
            page-break-inside: avoid;
        }
    }

    .ns-a4-sheet {
        width: 194mm;
        margin: 0 auto;
        background: #fff;
        color: #000;
        font-family: 'Sarabun', 'TH Sarabun New', 'Tahoma', sans-serif;
        font-size: 11pt;
        line-height: 1.35;
    }
</style>

<div class="ns-a4-sheet">

    {{-- Seller identity and the document's own title. The title has to be readable at a
         glance because this single sheet serves three purposes and the recipient files
         it under whichever one applies to them. --}}
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 4px;">
        <tr>
            <td style="vertical-align: top; width: 62%;">
                <div style="font-size: 15pt; font-weight: bold;">{{ $invoice->seller_name }}</div>
                @if ( ! empty( $invoice->seller_address ) )
                <div style="white-space: pre-line; font-size: 10pt;">{{ $invoice->seller_address }}</div>
                @endif
                @if ( ! empty( $invoice->seller_phone ) )
                <div style="font-size: 10pt;">TEL : {{ $invoice->seller_phone }}</div>
                @endif
                <div style="font-size: 10pt; margin-top: 2px;">
                    สาขาที่ออกใบกำกับภาษี :
                    <span style="font-weight: bold;">
                        {{ $invoice->seller_branch_code === ThaiVatService::HEAD_OFFICE_BRANCH_CODE
                            ? 'สำนักงานใหญ่'
                            : $invoice->seller_branch_code }}
                    </span>
                </div>
            </td>
            <td style="vertical-align: top; text-align: right;">
                <div style="border: {{ $border }}; padding: 4px 10px; display: inline-block; text-align: center;">
                    <div style="font-size: 12pt; font-weight: bold;">ใบส่งสินค้า/ใบกำกับภาษี/ใบเสร็จรับเงิน</div>
                    <div style="font-size: 10pt;">DELIVERY/INVOICE/RECEIPT</div>
                </div>
                <div style="font-size: 10pt; margin-top: 6px;">
                    เลขประจำตัวผู้เสียภาษี
                    <span style="font-weight: bold; margin-left: 6px;">{{ $invoice->seller_tax_id }}</span>
                </div>
            </td>
        </tr>
    </table>

    {{-- Buyer on the left, document identity on the right. A full form invoice exists so
         the buyer can claim the VAT as input tax, and it is unusable for that without
         their registration details. --}}
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
        <tr>
            <td style="width: 62%; vertical-align: top; padding-right: 6px;">
                <div style="font-size: 10pt; margin-bottom: 2px;">
                    นามผู้ซื้อ/ที่อยู่
                    <span style="margin-left: 14px;">รหัส : <span style="font-weight: bold;">{{ $invoice->buyer_code ?: '' }}</span></span>
                    <span style="margin-left: 14px;">โทร. : {{ $invoice->buyer_phone ?: '' }}</span>
                </div>
                <div style="border: {{ $border }}; padding: 5px; min-height: 76px;">
                    <div style="font-weight: bold;">{{ $invoice->buyer_name ?: '' }}</div>
                    @if ( ! empty( $invoice->buyer_address ) )
                    <div style="white-space: pre-line; font-size: 10pt;">{{ $invoice->buyer_address }}</div>
                    @endif
                    <div style="margin-top: 8px; font-size: 9.5pt;">
                        <span>เลขประจำตัวผู้เสียภาษี <span style="font-weight: bold;">{{ $invoice->buyer_tax_id ?: '' }}</span></span>
                        <span style="margin-left: 16px;">สถานประกอบการ <span style="font-weight: bold;">{{ $invoice->buyer_branch ?: '' }}</span></span>
                    </div>
                </div>
            </td>
            <td style="vertical-align: top;">
                <table style="width: 100%; border-collapse: collapse; font-size: 10pt;">
                    <tr>
                        <td style="{{ $cell }} background: #e8e8e8; text-align: right; width: 55%;">เลขที่</td>
                        <td style="{{ $cell }} font-weight: bold; font-size: 12pt;">{{ $invoice->number }}</td>
                    </tr>
                    <tr>
                        <td style="{{ $cell }} background: #e8e8e8; text-align: right;">วันที่</td>
                        <td style="{{ $cell }}">{{ ns()->date->getFormatted( $invoice->issued_at ) }}</td>
                    </tr>
                    <tr>
                        <td style="{{ $cell }} background: #e8e8e8; text-align: right;">เงื่อนไขการชำระเงิน</td>
                        <td style="{{ $cell }}">{{ $invoice->payment_terms ?: '' }}</td>
                    </tr>
                    <tr>
                        <td style="{{ $cell }} background: #e8e8e8; text-align: right;">กำหนดชำระเงิน</td>
                        <td style="{{ $cell }}">{{ $invoice->due_date ? $invoice->due_date->format( 'd/m/Y' ) : '' }}</td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

    @if ( $invoice->isCancelled() )
    <div style="border: 2px solid #000; padding: 4px; margin-bottom: 6px; text-align: center; font-weight: bold;">
        ยกเลิกแล้ว / CANCELLED
        @if ( ! empty( $invoice->cancellation_reason ) )
        <span style="font-weight: normal;">— {{ $invoice->cancellation_reason }}</span>
        @endif
    </div>
    @endif

    {{-- Line items --}}
    <table class="ns-a4-items" style="width: 100%; border-collapse: collapse; font-size: 10.5pt;">
        <thead>
            <tr style="background: #e8e8e8;">
                <th style="{{ $cell }} width: 34px; text-align: center;">ลำดับที่</th>
                <th style="{{ $cell }} text-align: center;">รายการสินค้า</th>
                <th style="{{ $cell }} width: 30px; text-align: center;">ภาษี</th>
                <th style="{{ $cell }} width: 80px; text-align: center;">จำนวนหน่วย</th>
                <th style="{{ $cell }} width: 78px; text-align: center;">ราคา</th>
                <th style="{{ $cell }} width: 88px; text-align: center;">จำนวนเงินรวม</th>
            </tr>
        </thead>
        <tbody>
            @foreach ( $lines as $index => $line )
            <tr>
                <td style="{{ $cell }} text-align: right;">{{ $index + 1 }}</td>
                <td style="{{ $cell }}">{{ $line[ 'name' ] }}</td>
                <td style="{{ $cell }} text-align: center;">{{ $taxMarks[ $line[ 'vat_status' ] ] ?? 'V' }}</td>
                <td style="{{ $cell }} text-align: right;">
                    {{ rtrim( rtrim( number_format( (float) $line[ 'quantity' ], 5, '.', '' ), '0' ), '.' ) }}
                    {{ $line[ 'unit_name' ] ?? '' }}
                </td>
                <td style="{{ $cell }} text-align: right;">{{ number_format( (float) $line[ 'unit_price' ], 2 ) }}</td>
                <td style="{{ $cell }} text-align: right;">{{ number_format( (float) $line[ 'gross' ], 2 ) }}</td>
            </tr>
            @endforeach

            @for ( $filler = 0; $filler < $fillerRows; $filler++ )
            <tr>
                <td style="{{ $cell }}">&nbsp;</td>
                <td style="{{ $cell }}"></td>
                <td style="{{ $cell }}"></td>
                <td style="{{ $cell }}"></td>
                <td style="{{ $cell }}"></td>
                <td style="{{ $cell }}"></td>
            </tr>
            @endfor
        </tbody>
    </table>

    <div class="ns-a4-closing">
        {{-- The legend and the VAT split. Section 86/4 requires the tax to be shown as
             its own amount, separate from the value of the goods; that separation is the
             whole reason the document exists. --}}
        <table style="width: 100%; border-collapse: collapse; font-size: 10pt;">
            <tr>
                <td style="{{ $cell }} border-top: none; vertical-align: top;">
                    <div>หมายเหตุ : {{ $taxLegend }}</div>
                    <div>ราคาสินค้ารวมภาษีมูลค่าเพิ่มแล้ว</div>
                </td>
                <td style="padding: 0; border: none; width: 268px; vertical-align: top;">
                    <table style="width: 100%; border-collapse: collapse;">
                        @if ( $hasExempt )
                        <tr>
                            <td style="{{ $cell }} border-top: none; text-align: right;">มูลค่าสินค้ายกเว้นภาษีมูลค่าเพิ่ม</td>
                            <td style="{{ $cell }} border-top: none; text-align: right; width: 88px;">{{ number_format( (float) $invoice->total_exempt, 2 ) }}</td>
                        </tr>
                        @endif
                        @if ( $hasZeroRated )
                        <tr>
                            <td style="{{ $cell }} text-align: right;">มูลค่าสินค้าอัตราร้อยละ 0</td>
                            <td style="{{ $cell }} text-align: right; width: 88px;">{{ number_format( (float) $invoice->total_zero_rated, 2 ) }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td style="{{ $cell }} {{ $hasExempt || $hasZeroRated ? '' : 'border-top: none;' }} text-align: right;">มูลค่าสินค้าคิดภาษีมูลค่าเพิ่ม</td>
                            <td style="{{ $cell }} {{ $hasExempt || $hasZeroRated ? '' : 'border-top: none;' }} text-align: right; width: 88px;">{{ number_format( (float) $invoice->total_net, 2 ) }}</td>
                        </tr>
                        <tr>
                            <td style="{{ $cell }} text-align: right;">ภาษีมูลค่าเพิ่ม&nbsp;&nbsp;{{ $rateLabel }} %</td>
                            <td style="{{ $cell }} text-align: right; width: 88px;">{{ number_format( (float) $invoice->total_vat, 2 ) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- The amount in words. A figure can have a digit added to it after the fact, a
             sentence cannot, which is why the written amount sits beside the total. --}}
        <table style="width: 100%; border-collapse: collapse; font-size: 11pt;">
            <tr>
                <td style="{{ $cell }} border-top: none; text-align: center; font-weight: bold;">
                    &lt;&lt;&lt; {{ $bahtText->convert( $invoice->total_gross ) }} &gt;&gt;&gt;
                </td>
                <td style="{{ $cell }} border-top: none; text-align: right; width: 180px;">จำนวนเงินรวมสุทธิ</td>
                <td style="{{ $cell }} border-top: none; text-align: right; width: 88px; font-weight: bold;">{{ number_format( (float) $invoice->total_gross, 2 ) }}</td>
            </tr>
        </table>

        {{-- Three acknowledgements, because this sheet is three documents: the buyer
             confirms receipt of the goods, the driver confirms the delivery, and the
             cashier confirms the money. --}}
        <table style="width: 100%; border-collapse: collapse; font-size: 10pt;">
            <tr>
                <td style="{{ $cell }} border-top: none; vertical-align: top; padding-bottom: 6px;">
                    <div style="margin-bottom: 26px;">ได้รับสินค้าดังกล่าวตามรายการข้างต้นไว้เป็นที่เรียบร้อยแล้ว</div>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="border: none; width: 50%; padding-right: 10px;">
                                <div style="border-top: 1px dotted #000; padding-top: 2px;">ผู้รับสินค้า</div>
                                <div style="border-top: 1px dotted #000; padding-top: 2px; margin-top: 14px;">วันที่</div>
                            </td>
                            <td style="border: none; width: 50%;">
                                <div style="border-top: 1px dotted #000; padding-top: 2px;">ผู้ส่งสินค้า</div>
                                <div style="border-top: 1px dotted #000; padding-top: 2px; margin-top: 14px;">วันที่</div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="{{ $cell }} border-top: none; width: 268px; vertical-align: bottom; text-align: center;">
                    <div style="height: 52px;"></div>
                    <div style="border-top: 1px dotted #000; padding-top: 3px;">ผู้รับเงิน/Receipt by</div>
                </td>
            </tr>
        </table>
    </div>

    @if ( ! empty( $footerMessage = ns()->option->get( 'ns_thai_tax_invoice_footer', '' ) ) )
    <div style="white-space: pre-line; font-size: 9.5pt; margin-top: 4px;">{{ $footerMessage }}</div>
    @endif

    <div style="font-size: 8.5pt; color: #555; margin-top: 3px;">
        ออกโดย {{ $invoice->user->username ?? '-' }}
        <span style="margin-left: 10px;">อ้างอิงบิล {{ $order->code }}</span>
    </div>
</div>
