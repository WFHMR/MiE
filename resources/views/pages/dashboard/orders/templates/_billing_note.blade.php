<?php
use App\Services\ThaiBahtTextService;

/**
 * ใบแจ้งหนี้/วางบิลค่าสินค้า (ชั่วคราว) — the A4 billing note.
 *
 * Transcribed from หจก. รุ่งเรืองแกรนด์ note 69080001. The customer's accounts desk
 * checks this against their own ledger line by line, so the arrangement matters as much
 * as the content: the document number top right, the customer block top left, the
 * running list in the middle with generous blank space beneath it, and the three totals
 * bottom right where a clerk expects to tick them off.
 *
 * Unlike the tax invoice this form is ruled rather than boxed — horizontal lines only,
 * no vertical borders in the item list. That is deliberate on the original: the desk
 * writes deductions and queries in the margins.
 *
 * Everything prints from the note's own frozen snapshot. The orders it lists can be paid,
 * edited or voided after the note is handed over, and none of that may change the sheet
 * the customer is holding.
 */
$lines = collect( $note->lines ?? [] );

$bahtText = app()->make( ThaiBahtTextService::class );

$rule = '1px solid #000';

/**
 * A date that may be absent prints blank rather than as a dash: the desk writes the real
 * one in, and a dash reads as "none".
 */
$printDate = fn ( ?string $date ) => $date ? \Carbon\Carbon::parse( $date )->format( 'd/m/Y' ) : '';
?>
<style>
    /**
     * Declared here rather than in the global stylesheet so it cannot reach the 58mm
     * thermal receipt, which has no business being padded out to A4.
     */
    @page {
        size: A4 portrait;
        margin: 12mm 10mm;
    }

    @media print {
        body {
            margin: 0;
            background: #fff;
        }

        .ns-bn-items thead {
            display: table-header-group;
        }

        .ns-bn-items tr {
            page-break-inside: avoid;
        }

        .ns-bn-closing {
            page-break-inside: avoid;
        }
    }

    .ns-bn-sheet {
        width: 190mm;
        min-height: 270mm;
        display: flex;
        flex-direction: column;
        margin: 0 auto;
        background: #fff;
        color: #000;
        font-family: 'Sarabun', 'TH Sarabun New', 'Tahoma', sans-serif;
        font-size: 11pt;
        line-height: 1.4;
    }

    .ns-bn-sheet > * { flex-shrink: 0; }
    .ns-bn-sheet td { overflow-wrap: anywhere; }
    .ns-bn-closing { margin-top: auto; padding-top: 12mm; }

    .ns-bn-sheet .ns-bn-label {
        white-space: nowrap;
    }
</style>

<div class="ns-bn-sheet">

    {{-- Letterhead. Centred on the original, with the contact line ruled off beneath it. --}}
    <div style="text-align: center;">
        <div style="font-size: 20pt; font-weight: bold;">{{ $note->seller_name }}</div>
        <div style="border-bottom: {{ $rule }}; padding-bottom: 3px; font-size: 11pt;">
            {{ str_replace( "\n", ' ', (string) $note->seller_address ) }}
            @if ( ! empty( $note->seller_phone ) )
                TEL : {{ $note->seller_phone }}
            @endif
            @if ( ! empty( $note->seller_fax ) )
                Fax : {{ $note->seller_fax }}
            @endif
        </div>
    </div>

    {{-- The title carries "(ชั่วคราว)" because this document is provisional: it asks for
         payment against tax invoices already issued, and creates no liability itself. --}}
    <div style="text-align: center; margin: 10px 0 6px;">
        <span style="font-size: 18pt; font-weight: bold; border-bottom: {{ $rule }}; padding-bottom: 1px;">
            {{ __( 'ใบแจ้งหนี้/วางบิลค่าสินค้า (ชั่วคราว)' ) }}
        </span>
    </div>

    <div style="text-align: right; font-size: 11pt;">
        <span class="ns-bn-label">{{ __( 'เลขที่' ) }}</span>
        <span style="font-weight: bold; font-size: 13pt; margin-left: 10px;">{{ $note->number }}</span>
    </div>

    {{-- Customer block left, reference and dates right. --}}
    <table style="width: 100%; border-collapse: collapse; font-size: 11pt; margin-top: 2px;">
        <tr>
            <td style="width: 15%; vertical-align: top;" class="ns-bn-label">{{ __( 'ชื่อลูกค้า' ) }}</td>
            <td style="width: 37%; vertical-align: top; font-weight: bold;">{{ $note->customer_name }}</td>
            <td style="width: 12%; vertical-align: top; text-align: right;" class="ns-bn-label">{{ __( 'รหัส' ) }}</td>
            <td style="width: 16%; vertical-align: top; font-weight: bold; padding-left: 8px;">{{ $note->customer_code }}</td>
            <td style="width: 10%; vertical-align: top; text-align: right;" class="ns-bn-label">{{ __( 'วันที่' ) }}</td>
            <td style="width: 10%; vertical-align: top; font-weight: bold; text-align: right; white-space: nowrap;">{{ $note->issued_at->format( 'd/m/Y' ) }}</td>
        </tr>
        <tr>
            <td style="vertical-align: top;" class="ns-bn-label">{{ __( 'ที่อยู่' ) }}</td>
            <td colspan="2" style="vertical-align: top; font-weight: bold; white-space: pre-line;">{{ $note->customer_address }}</td>
            <td colspan="2" style="vertical-align: top; text-align: right;" class="ns-bn-label">{{ __( 'นัดชำระวันที่' ) }}</td>
            <td style="vertical-align: top; font-weight: bold; text-align: right; white-space: nowrap;">
                {{ $note->appointment_date ? $note->appointment_date->format( 'd/m/Y' ) : '' }}
            </td>
        </tr>
    </table>

    <div style="margin-top: 5px; font-size: 11pt;">
        โทรศัพท์ลูกค้า: {{ $note->customer?->phone ?: 'ไม่ได้ระบุ' }}
        <span style="font-size: 9pt;">(ข้อมูลติดต่อปัจจุบัน)</span>
    </div>

    @if ( $note->isCancelled() )
    <div style="border: 2px solid #000; padding: 4px; margin: 6px 0; text-align: center; font-weight: bold;">
        {{ __( 'ยกเลิกแล้ว / CANCELLED' ) }}
        @if ( ! empty( $note->cancellation_reason ) )
        <span style="font-weight: normal;">— {{ $note->cancellation_reason }}</span>
        @endif
    </div>
    @endif

    <table style="width: 100%; border-collapse: collapse; font-size: 11pt; margin-top: 8px;">
        <tr>
            <td>{{ __( 'ขอวางบิลค่าสินค้าตามรายการดังต่อไปนี้' ) }}</td>

        </tr>
    </table>

    {{-- The list of documents being asked for. Ruled top and bottom only, as the paper
         form is, so the desk can annotate individual rows. --}}
    <table class="ns-bn-items" style="width: 100%; border-collapse: collapse; font-size: 11pt;">
        <thead>
            <tr>
                <th style="border-bottom: {{ $rule }}; padding: 4px 6px; width: 60px; text-align: center;">{{ __( 'ลำดับที่' ) }}</th>
                <th style="border-bottom: {{ $rule }}; padding: 4px 6px; text-align: left;">{{ __( 'ใบส่งสินค้าเลขที่' ) }}</th>
                <th style="border-bottom: {{ $rule }}; padding: 4px 6px; width: 100px; text-align: center;">{{ __( 'ลงวันที่' ) }}</th>
                <th style="border-bottom: {{ $rule }}; padding: 4px 6px; width: 120px; text-align: center;">{{ __( 'กำหนดชำระ (วัน)' ) }}</th>
                <th style="border-bottom: {{ $rule }}; padding: 4px 6px; width: 130px; text-align: center;">{{ __( 'ครบกำหนดวันที่' ) }}</th>
                <th style="border-bottom: {{ $rule }}; padding: 4px 6px; width: 120px; text-align: right;">{{ __( 'จำนวนเงินรวม' ) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ( $lines as $index => $line )
            <tr>
                <td style="padding: 3px 6px; text-align: right;">{{ $index + 1 }}</td>
                <td style="padding: 3px 6px;">{{ $line[ 'document_number' ] }}</td>
                <td style="padding: 3px 6px; text-align: center;">{{ $printDate( $line[ 'document_date' ] ?? null ) }}</td>
                <td style="padding: 3px 6px; text-align: center;">{{ $line[ 'payment_term_days' ] ?: '' }}</td>
                <td style="padding: 3px 6px; text-align: center;">{{ $printDate( $line[ 'due_date' ] ?? null ) }}</td>
                <td style="padding: 3px 6px; text-align: right;">{{ number_format( (float) $line[ 'amount' ], 2 ) }}</td>
            </tr>
            @endforeach


        </tbody>
    </table>

    <div class="ns-bn-closing">
        <table style="width: 100%; border-collapse: collapse; font-size: 11pt; border-top: {{ $rule }};">
            <tr>
                <td style="vertical-align: bottom; padding-top: 6px;"></td>
                <td style="text-align: right; padding: 2px 8px 2px 0; width: 200px;" class="ns-bn-label">{{ __( 'จำนวนเงินที่วางบิลรวม' ) }}</td>
                <td style="text-align: right; width: 110px; font-weight: bold;">{{ number_format( (float) $note->total_billed, 2 ) }}</td>
            </tr>
            <tr>
                <td></td>
                <td style="text-align: right; padding: 2px 8px 2px 0;" class="ns-bn-label">{{ __( 'หักตามหมายเหตุรวม' ) }}</td>
                <td style="text-align: right; font-weight: bold;">{{ number_format( (float) $note->total_deducted, 2 ) }}</td>
            </tr>
            <tr>
                {{-- The written amount sits beside the net figure for the same reason it does
                     on the invoice: a figure can have a digit added to it after the fact, a
                     sentence cannot. It states the net, which is the amount actually being
                     accepted for payment. --}}
                <td style="text-align: center; font-weight: bold;">
                    &lt;&lt;&lt; {{ $bahtText->convert( $note->total_net ) }} &gt;&gt;&gt;
                </td>
                <td style="text-align: right; padding: 2px 8px 2px 0;" class="ns-bn-label">{{ __( 'จำนวนเงินรับวางบิลสุทธิ' ) }}</td>
                <td style="text-align: right; font-weight: bold;">{{ number_format( (float) $note->total_net, 2 ) }}</td>
            </tr>
        </table>

        {{-- หมายเหตุ. Ruled off with space beneath because the reason for a deduction is
             written here by hand at the desk. --}}
        <div style="border-top: {{ $rule }}; margin-top: 4px; padding-top: 6px; font-size: 11pt;">
            <span class="ns-bn-label">{{ __( 'หมายเหตุ :' ) }}</span>
            <span style="white-space: pre-line;">{{ $note->notes }}</span>
        </div>
        <div style="height: 70px; border-bottom: {{ $rule }};"></div>

        {{-- Three acknowledgements: the runner who left the bill, the desk that accepted
             it, and the finance officer who schedules the payment. All three sign on the
             original, and the shop's copy is only useful if all three did. --}}
        <table style="width: 100%; border-collapse: collapse; font-size: 11pt; margin-top: 46px;">
            <tr>
                <td style="width: 33.33%; padding-right: 20px;">
                    <div style="border-top: {{ $rule }}; padding-top: 3px; text-align: center;">{{ __( 'ผู้วางบิล' ) }}</div>
                </td>
                <td style="width: 33.33%; padding: 0 10px;">
                    <div style="border-top: {{ $rule }}; padding-top: 3px; text-align: center;">{{ __( 'ผู้รับวางบิล' ) }}</div>
                </td>
                <td style="width: 33.33%; padding-left: 20px;">
                    <div style="border-top: {{ $rule }}; padding-top: 3px; text-align: center;">{{ __( 'ฝ่ายการเงิน/บัญชี' ) }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div style="border-top: {{ $rule }}; margin-top: 8px; padding-top: 3px; font-size: 10pt; color: #111;">
        {{ __( 'ออกโดย' ) }} {{ $note->user->username ?? '-' }}
    </div>
</div>

@includeWhen( request()->query( 'autoprint' ) === 'true', '/pages/dashboard/orders/templates/_autoprint' )
