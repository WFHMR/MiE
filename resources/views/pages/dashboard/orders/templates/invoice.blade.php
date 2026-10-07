<?php

use App\Classes\Hook;
use App\Models\Order;

?>
@extends(request()->boolean('print') ? 'layout.base' : 'layout.dashboard')

<?php
$isDue  =   in_array( $order->payment_status, [ Order::PAYMENT_PARTIALLY, Order::PAYMENT_UNPAID ] );
?>

@section(request()->boolean('print') ? 'layout.base.body' : 'layout.dashboard.body')
<div class="order-document-page">
    @unless(request()->boolean('print'))
    @include( Hook::filter( 'ns-dashboard-header-file', '../common/dashboard-header' ) )
    @endunless
    <div class="order-document-tools" style="padding:16px;display:flex;gap:16px;flex-wrap:wrap;color:#173c40">
    @if(request()->boolean('print'))<button onclick="window.print()" style="background:#267c79;color:white;padding:10px 20px;border-radius:6px">พิมพ์ / บันทึก PDF</button>
    @else<a target="_blank" rel="noopener" href="{{ route('ns.dashboard.orders-invoice',['order'=>$order->id,'print'=>1,'document'=>request()->query('document')]) }}" style="background:#267c79;color:white;padding:10px 20px;border-radius:6px">พิมพ์เอกสาร A4</a>@endif
    <a href="{{ route('ns.dashboard.orders-invoice',['order'=>$order->id,'document'=>'temporary','print'=>1]) }}">ใบส่งสินค้า/ใบฝากสินค้าชั่วคราว</a>
    <a href="{{ route('ns.dashboard.orders') }}">กลับรายการขาย</a>
    @unless($invoice)<p>ใบกำกับภาษีแบบเต็มรูป: ออกเอกสารจากรายการขายก่อน ระบบจะใช้เลขที่และข้อมูลใบกำกับภาษีที่ออกแล้วในหน้านี้</p>@endunless
    </div>
    <div class="order-document-preview" style="overflow:auto;padding:12px">
    @if($invoice)
    @include('pages.dashboard.orders.templates._tax_invoice_a4')
    @else
    @include('pages.dashboard.orders.templates._order_document_a4')
    @endif
    </div>
</div>
<style>@page{size:A4 portrait;margin:10mm 8mm}@media print{.order-document-tools{display:none!important}.order-document-preview{overflow:visible!important;padding:0!important}body{background:white!important;margin:0!important}.order-document-page{color:#000;background:#fff}}</style>
@endsection
