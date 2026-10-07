<?php
use App\Models\Order;
use App\Classes\Hook;
use Illuminate\Support\Facades\View;

$prefered_price     =   $order->settings?->where( 'key', 'ns_pos_prefered_price' )->first()?->value;
$pos_vat          =   $order->settings?->where( 'key', 'ns_pos_vat' )->first()?->value;

$storeName = ns()->option->get( 'ns_store_name', 'RGBF' );
$storeAddress = ns()->option->get( 'ns_invoice_receipt_column_a', '' );
$storePhone = ns()->option->get( 'ns_store_phone', '' );
/**
 * Padded rather than read straight: the option layer turns a digit only value into an integer, so a
 * taxpayer number beginning with zero came back one digit short and every receipt printed an invalid
 * number. The stored value is fine; only the reading of it needed fixing.
 */
$storeTaxId = app( \App\Services\ThaiVatService::class )->formatTaxpayerId(
    ns()->option->get( 'ns_store_tax_id', '' )
);
$storeLogo = ns()->option->get( 'ns_invoice_receipt_logo', '' );
$footerMessage = ns()->option->get( 'ns_invoice_receipt_footer', 'ขอบคุณที่ใช้บริการ' );
?>
<div class="w-full h-full">
    @include( 'pages.dashboard.orders.templates._thermal-receipt-style' )
    <div class="rgbf-thermal-receipt" style="width: 302px; background: #fff; margin: 0 auto; padding: 24px 16px;">
        
        {{-- Header: Date & Receipt Number --}}
        <div class="receipt-meta" style="font-size: 12px; color: #000; display: flex; justify-content: space-between; margin-bottom: 8px;">
            <span>{{ ns()->date->getFormatted( $order->created_at ) }}</span>
            <span>เลขที่: {{ $order->code }}</span>
        </div>

        {{-- Logo --}}
        <div style="text-align: center; margin-bottom: 8px;">
            @if ( !empty($storeLogo) )
            <img src="{{ $storeLogo }}" alt="{{ $storeName }}" style="max-height: 50px; margin: 0 auto; display: block;">
            @endif
        </div>

        {{-- Store Info --}}
        <div style="text-align: center; font-size: 13px; color: #000; line-height: 1.6;">
            <div style="font-size: 16px; font-weight: bold;">{{ $storeName }}</div>
            @if( !empty($storeAddress) )
            <div style="white-space: pre-line; font-size: 13px; margin-top: 4px;">{{ $storeAddress }}</div>
            @endif
            @if( !empty($storePhone) )
            <div style="font-size: 13px;">โทร: {{ $storePhone }}</div>
            @endif
            @if( !empty($storeTaxId) )
            <div style="font-size: 13px;">เลขผู้เสียภาษี: {{ $storeTaxId }}</div>
            @endif
        </div>

        {{-- Divider --}}
        <div style="border-top: 1px dashed #000; margin: 10px 0;"></div>

        @include('pages.dashboard.orders.templates._receipt-member')

        {{-- Cashier Info --}}
        <div style="font-size: 12px; color: #000; margin-bottom: 4px;">
            พนักงาน: {{ $order->user->username ?? 'Admin' }} | {{ ns()->date->getFormatted( $order->created_at, 'time' ) }}
        </div>

        {{-- Divider --}}
        <div style="border-top: 1px dashed #000; margin: 8px 0;"></div>

        {{-- Column Header --}}
        <div class="receipt-columns" style="font-size: 12px; font-weight: bold; display: flex; justify-content: space-between; padding-bottom: 3px; border-bottom: 1px solid #000;">
            <span style="flex: 2;">รายการ</span>
            <span style="flex: 0.8; text-align: center;">จำนวน</span>
            <span style="flex: 1; text-align: right;">ราคา</span>
        </div>

        {{-- Products --}}
        @foreach( Hook::filter( 'ns-receipt-products', $order->combinedProducts ) as $product )
        <div class="receipt-columns" style="font-size: 13px; padding: 3px 0; display: flex; justify-content: space-between; border-bottom: 1px dotted #000;">
            <span class="receipt-product-name" title="{{ $product->name }}" style="flex: 2;">{{ $product->name }}</span>
            <span class="receipt-quantity" style="flex: 0.8; text-align: center;">{{ (int)$product->quantity }}</span>
            <span class="receipt-amount" style="flex: 1; text-align: right;">{{ number_format($product->total_price, 2) }}</span>
        </div>
        @endforeach

        {{-- Divider --}}
        <div style="border-top: 1px dashed #000; margin: 8px 0;"></div>

        {{-- Totals --}}
        <div style="font-size: 13px;">
            @if ( $order->subtotal != $order->total )
            <div class="receipt-summary-row" style="display: flex; justify-content: space-between; padding: 2px 0;">
                <span>ยอดรวมย่อย:</span>
                <span class="receipt-amount">{{ ns()->currency->define( $order->subtotal ) }}</span>
            </div>
            @endif

            @if ( $order->discount > 0 )
            <div class="receipt-summary-row" style="display: flex; justify-content: space-between; padding: 2px 0;">
                <span>ส่วนลด:</span>
                <span class="receipt-amount">-{{ ns()->currency->define( $order->discount ) }}</span>
            </div>
            @endif

            @if ( $order->shipping > 0 )
            <div class="receipt-summary-row" style="display: flex; justify-content: space-between; padding: 2px 0;">
                <span>ค่าจัดส่ง:</span>
                <span class="receipt-amount">{{ ns()->currency->define( $order->shipping ) }}</span>
            </div>
            @endif

            @if ( $order->tax_value > 0 )
            {{--
                มูลค่าสินค้าก่อนภาษี แสดงคู่กับภาษีเสมอ

                เดิมพิมพ์แค่ "ภาษี" แล้วต่อด้วย "รวมทั้งสิ้น" คนอ่านจึงเอาสองตัวบวกกัน
                และสรุปว่าระบบลืมบวกภาษีเข้ายอด ทั้งที่ราคาสินค้าของร้านรวม VAT อยู่แล้ว
                (สินค้าทุกตัวตั้ง tax_type = inclusive และ ns_pos_tax_type = inclusive)
                ถ้าบวกจริงจะเก็บภาษีซ้ำสองรอบและนำส่งภาษีขายเกินความจริง

                สามบรรทัดนี้ทำให้เลขอธิบายตัวเองได้ 83.18 + 5.82 = 89.00 และเป็นรูปแบบที่
                ใบกำกับภาษีเต็มรูปต้องแสดงตามประมวลรัษฎากร
            --}}
            <div class="receipt-summary-row" style="display: flex; justify-content: space-between; padding: 2px 0;">
                <span>มูลค่าสินค้าก่อนภาษี:</span>
                <span class="receipt-amount">{{ ns()->currency->define( $order->total - $order->tax_value ) }}</span>
            </div>
            <div class="receipt-summary-row" style="display: flex; justify-content: space-between; padding: 2px 0;">
                <span>ภาษีมูลค่าเพิ่ม 7%:</span>
                <span class="receipt-amount">{{ ns()->currency->define( $order->tax_value ) }}</span>
            </div>
            <div style="font-size: 12px;">(รวมในราคาแล้ว)</div>
            @endif

            @foreach(($order->coupons ?? collect()) as $coupon)
            <div class="receipt-summary-row" style="display:flex;justify-content:space-between;padding:2px 0;">
                <span>คูปอง {{ $coupon->name }} @if($coupon->code) ({{ $coupon->code }}) @endif</span>
                <span class="receipt-amount">-{{ number_format((float)$coupon->value, 2) }}</span>
            </div>
            @endforeach
            {{-- Total --}}
            <div class="receipt-summary-row" style="display: flex; justify-content: space-between; padding: 4px 0; font-weight: bold; font-size: 15px; border-top: 1px solid #000; border-bottom: 1px solid #000; margin: 4px 0;">
                <span>รวมทั้งสิ้น:</span>
                <span class="receipt-amount">{{ ns()->currency->define( $order->total ) }}</span>
            </div>
        </div>

        {{-- Payment --}}
        <div style="font-size: 13px; margin-top: 6px;">
            @foreach( $order->payments as $payment )
            <div class="receipt-summary-row" style="display: flex; justify-content: space-between; padding: 2px 0;">
                <span>ชำระโดย: {{ $paymentTypes[ $payment[ 'identifier' ] ] ?? $payment[ 'identifier' ] }}</span>
                <span class="receipt-amount">{{ ns()->currency->define( $payment[ 'value' ] ) }}</span>
            </div>
            @endforeach

            @if ( $order->payment_status === 'paid' && $order->change > 0 )
            <div class="receipt-summary-row" style="display: flex; justify-content: space-between; padding: 2px 0;">
                <span>เงินทอน:</span>
                <span class="receipt-amount">{{ ns()->currency->define( $order->change ) }}</span>
            </div>
            @endif
        </div>

        {{-- Divider --}}
        <div style="border-top: 1px dashed #000; margin: 10px 0;"></div>

        {{-- Footer --}}
        <div style="text-align: center; font-size: 13px; font-weight: bold; color: #000;">
            {{ $footerMessage }}
        </div>
        <div style="text-align: center; font-size: 12px; color: #000; margin-top: 6px;">
            {{ ns()->date->getFormatted( $order->created_at ) }}
        </div>
    </div>
</div>
@includeWhen( request()->query( 'autoprint' ) === 'true', '/pages/dashboard/orders/templates/_autoprint' )
