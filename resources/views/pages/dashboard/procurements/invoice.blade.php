@extends( 'layout.dashboard' )

@section( 'layout.dashboard.body' )
<div class="h-full flex-auto flex flex-col">
    @include( Hook::filter( 'ns-dashboard-header-file', '../common/dashboard-header' ) )
    <div class="px-4 flex-auto flex flex-col" id="dashboard-content">
        @include( 'common.dashboard.title' )
        <ns-procurement-invoice></ns-procurement-invoice>
    </div>
</div>
<?php
/**
 * A date that may be absent prints blank rather than as a dash: a purchase is raised before its
 * delivery note and its billing note exist, and a dash reads as "there isn't one".
 */
$printDate = fn ( ?string $date ) => $date ? \Carbon\Carbon::parse( $date )->format( 'd/m/Y' ) : '';

/**
 * cost is the tax-inclusive total — ns-procurement.vue sets each line's purchase_price to the gross
 * price under both tax types — so the net value of the goods is what is left once the tax comes off.
 */
$taxValue = (float) $procurement->tax_value;
$grandTotal = (float) $procurement->cost;
$netGoodsValue = max( 0, $grandTotal - $taxValue );
?>
<style>
    /**
     * Declared here rather than in the global stylesheet so it cannot reach the 58mm thermal
     * receipt, which has no business being padded out to A4.
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

        .ns-pr-items thead {
            display: table-header-group;
        }

        .ns-pr-items tr {
            page-break-inside: avoid;
        }

        .ns-pr-closing {
            page-break-inside: avoid;
        }
    }
</style>
<script type="text/x-template" id="ns-procurement-invoice">
<div class="p-4 shadow ns-box">
    <div id="printable-container">

        <div class="my-4 flex justify-between items-start">
            <div>
                <img style="display: block; width: auto; height: auto; max-width: 96px; max-height: 96px; object-fit: contain; object-position: left top;" src="{{ ns_logo( 'rectangle' ) }}" alt="{{ ns()->option->get( 'ns_store_name' ) }}">
            </div>
            <div class="text-right text-font">
                <div class="font-bold text-lg">{{ __( 'ใบรับของ / ใบตรวจรับสินค้า' ) }}</div>
                <div class="text-sm">{{ __( 'เลขที่รายการ' ) }} <span class="font-bold">{{ $procurement->name }}</span></div>
                <div class="text-sm">{{ __( 'วันที่พิมพ์' ) }} {{ $printDate( now()->toDateString() ) }}</div>
            </div>
        </div>

        {{-- The paperwork this purchase travels with. Receiving checks the goods against the
             delivery note; accounts pays against the billing note and has to be able to see which
             receipt it belongs to, or the payment is filed against the wrong one. --}}
        <div class="my-4">
            <table class="w-full text-font" style="border-collapse: collapse; font-size: 10.5pt;">
                <tr>
                    <td class="p-1" style="width: 16%;">{{ __( 'เลขที่ใบสั่งซื้อ' ) }}</td>
                    <td class="p-1 font-bold" style="width: 17%;">{{ $procurement->purchase_order_number }}</td>
                    <td class="p-1" style="width: 16%;">{{ __( 'เลขที่ใบส่งสินค้า' ) }}</td>
                    <td class="p-1 font-bold" style="width: 17%;">{{ $procurement->delivery_note_number }}</td>
                    <td class="p-1" style="width: 16%;">{{ __( 'ลงวันที่' ) }}</td>
                    <td class="p-1 font-bold" style="width: 18%;">{{ $printDate( $procurement->delivery_note_date ) }}</td>
                </tr>
                <tr>
                    <td class="p-1">{{ __( 'เลขที่ใบวางบิล' ) }}</td>
                    <td class="p-1 font-bold">{{ $procurement->billing_note_number }}</td>
                    <td class="p-1">{{ __( 'ลงวันที่' ) }}</td>
                    <td class="p-1 font-bold">{{ $printDate( $procurement->billing_note_date ) }}</td>
                    <td class="p-1">{{ __( 'เลขที่ใบกำกับภาษี' ) }}</td>
                    <td class="p-1 font-bold">{{ $procurement->invoice_reference }}</td>
                </tr>
                <tr>
                    <td class="p-1">{{ __( 'เครดิต (วัน)' ) }}</td>
                    <td class="p-1 font-bold">{{ (int) $procurement->credit_days > 0 ? (int) $procurement->credit_days : '' }}</td>
                    <td class="p-1">{{ __( 'ครบกำหนดชำระ' ) }}</td>
                    <td class="p-1 font-bold">{{ $printDate( $procurement->due_date ) }}</td>
                    <td class="p-1">{{ __( 'ผู้ติดต่อ / ผู้รับเรื่อง' ) }}</td>
                    <td class="p-1 font-bold">{{ $procurement->contact_person }}</td>
                </tr>
            </table>
        </div>
        <div class="flex flex-wrap -mx-3 text-font">
            <div class="px-3 w-full print:w-1/2 md:w-1/2">
                <h3 class="font-semibold text-xl border-b-2 border-blue-400 py-2 mb-2">{{ __( 'ผู้ขาย' ) }}</h3>
                {{-- The supplier may resolve to no row at all: the bulk stock importer stores a
                     provider_id of zero, and this document has to print for those purchases too
                     rather than failing on a null. --}}
                <ul>
                    <li class="py-1"><span class="font-bold">{{ __( 'รหัสผู้ขาย' ) }}: </span>{{ $procurement->provider->code ?? __( 'ไม่มีรหัส' ) }}</li>
                    <li class="py-1"><span class="font-bold">{{ __( 'ชื่อบริษัท' ) }}: </span>{{ $procurement->provider->first_name ?? __( 'ไม่ระบุผู้ขาย' ) }}</li>
                    <li class="py-1"><span class="font-bold">{{ __( 'โทรศัพท์' ) }}: </span>{{ $procurement->provider->phone ?? '' }}</li>
                    <li class="py-1"><span class="font-bold">{{ __( 'อีเมล' ) }}: </span>{{ $procurement->provider->email ?? '' }}</li>
                    <li class="py-1"><span class="font-bold">{{ __( 'ที่อยู่' ) }}: </span>{{ $procurement->provider->address_1 ?? '' }}</li>
                    <li class="py-1"><span class="font-bold">{{ __( 'ที่อยู่ (ต่อ)' ) }}: </span>{{ $procurement->provider->address_2 ?? '' }}</li>
                </ul>
            </div>
            <div class="px-3 w-full print:w-1/2 md:w-1/2">
                <h3 class="font-semibold text-xl border-b-2 border-blue-400 py-2 mb-2">Store</h3>
                <ul>
                    <li class="py-1"><span class="font-bold">{{ __( 'Name' ) }}: </span> {{ $options->get( 'ns_store_name' ) }}</li>
                    <li class="py-1"><span class="font-bold">{{ __( 'Address' ) }}: </span>{{ $options->get( 'ns_store_address' ) ?? __( 'N/A' ) }}</li>
                    <li class="py-1"><span class="font-bold">{{ __( 'City' ) }}: </span>{{ $options->get( 'ns_store_city' ) ?? __( 'N/A' ) }}</li>
                    <li class="py-1"><span class="font-bold">{{ __( 'Phone' ) }}: </span>{{ $options->get( 'ns_store_phone' ) ?? __( 'N/A' ) }}</li>
                    <li class="py-1"><span class="font-bold">{{ __( 'Email' ) }}: </span>{{ $options->get( 'ns_store_email' ) ?? __( 'N/A' ) }}</li>
                    <li class="py-1"><span class="font-bold">{{ __( 'PO.Box' ) }}: </span>{{ $options->get( 'ns_store_pobox' ) ?? __( 'N/A' ) }}</li>
                </ul>
            </div>
        </div>
        <div class="my-4">
            {{-- Price and quantity per line, because that is exactly what the receiving desk checks
                 against the goods that turned up. --}}
            <table class="ns-table ns-pr-items">
                <thead class="border">
                    <tr>
                        <th>{{ __( 'สินค้า' ) }}</th>
                        <th>{{ __( 'หน่วย' ) }}</th>
                        <th>{{ __( 'ราคาต่อหน่วย' ) }}</th>
                        <th>{{ __( 'จำนวน' ) }}</th>
                        <th>{{ __( 'รวมเงิน' ) }}</th>
                        <th style="width: 90px;">{{ __( 'ตรวจรับ' ) }}</th>
                    </tr>
                </thead>
                <tbody class="border">
                    @foreach( $procurement->products as $product )
                    <tr>
                        <td class="border p-1">
                            <h3 class="font-semibold">{{ $product->name }}</h3>
                            <p class="text-xs">{{ __( 'Barcode' ) }}: {{ $product->barcode }}</p>
                        </td>
                        <td class="border p-1">{{ $product->unit->name ?? '' }}</td>
                        <td class="text-right border p-1">{{ ( string ) ns()->currency->define( $product->purchase_price ) }}</td>
                        <td class="text-right border p-1">{{ $product->quantity }}</td>
                        <td class="text-right border p-1">{{ ( string ) ns()->currency->define( $product->total_purchase_price ) }}</td>
                        {{-- Left empty on purpose: the desk ticks each line by hand as it counts it
                             off the pallet. A column printed full is a column nobody checks. --}}
                        <td class="border p-1"></td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot class="border ns-pr-closing">
                    <tr>
                        <td colspan="3"></td>
                        <td class="border p-1">{{ __( 'มูลค่าสินค้าสุทธิ' ) }}</td>
                        <td class="text-right border p-1">{{ ( string ) ns()->currency->define( $netGoodsValue ) }}</td>
                        <td class="border p-1"></td>
                    </tr>
                    <tr>
                        <td colspan="3"></td>
                        <td class="border p-1">{{ __( 'ภาษีมูลค่าเพิ่ม' ) }}</td>
                        <td class="text-right border p-1">{{ ( string ) ns()->currency->define( $taxValue ) }}</td>
                        <td class="border p-1"></td>
                    </tr>
                    <tr>
                        <td colspan="3"></td>
                        <td class="border p-1 font-bold">{{ __( 'จำนวนเงินรวม' ) }}</td>
                        <td class="text-right border p-1 font-bold">{{ ( string ) ns()->currency->define( $grandTotal ) }}</td>
                        <td class="border p-1"></td>
                    </tr>
                    <tr>
                        <td colspan="3"></td>
                        <td class="border p-1">{{ __( 'สถานะการรับของ' ) }}</td>
                        <td class="text-right border p-1">{{ ns()->procurement->getDeliveryLabel( $procurement->delivery_status ) }}</td>
                        <td class="border p-1"></td>
                    </tr>
                    <tr>
                        <td colspan="3"></td>
                        <td class="border p-1">{{ __( 'สถานะการชำระเงิน' ) }}</td>
                        <td class="text-right border p-1">{{ ns()->procurement->getPaymentLabel( $procurement->payment_status ) }}</td>
                        <td class="border p-1"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- Three signatures, because three people are answerable for a receipt: whoever delivered
             it, whoever counted it in, and whoever approved paying for it. The shop's copy is only
             worth keeping if all three signed. --}}
        <div class="ns-pr-closing" style="margin-top: 40px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 10.5pt;">
                <tr>
                    <td style="width: 33.33%; padding-right: 20px;">
                        <div style="border-top: 1px solid #000; padding-top: 3px; text-align: center;">{{ __( 'ผู้ส่งของ' ) }}</div>
                    </td>
                    <td style="width: 33.33%; padding: 0 10px;">
                        <div style="border-top: 1px solid #000; padding-top: 3px; text-align: center;">{{ __( 'ผู้ตรวจรับ (แผนกรับเข้า)' ) }}</div>
                    </td>
                    <td style="width: 33.33%; padding-left: 20px;">
                        <div style="border-top: 1px solid #000; padding-top: 3px; text-align: center;">{{ __( 'ผู้อนุมัติจ่าย (บัญชี)' ) }}</div>
                    </td>
                </tr>
            </table>
            <div style="border-top: 1px solid #ccc; margin-top: 8px; padding-top: 3px; font-size: 8.5pt; color: #555;">
                {{ __( 'บันทึกโดย' ) }} {{ $procurement->user->username ?? '-' }}
            </div>
        </div>
    </div>
    <div class="flex justify-between">
        <div></div>
        <div>
            <ns-button @click="printInvoice()" type="info">
                <i class="las la-print"></i>
                {{ __( 'พิมพ์' ) }}
            </ns-button>
        </div>
    </div>
</div>
</script>
{{-- Reached with ?autoprint=true from the Print action on the list, so the desk gets paper in one
     click instead of opening the document and then hunting for a button. --}}
@includeWhen( request()->query( 'autoprint' ) === 'true', '/pages/dashboard/orders/templates/_autoprint' )
@endsection
