@extends('layout.dashboard')
@section('layout.dashboard.body')
@include(Hook::filter('ns-dashboard-header-file','../common/dashboard-header'))
<div class="px-4 flex-auto flex flex-col" id="dashboard-content">
@include('common.dashboard.title')
<div class="ns-notice info my-4"><p>ใบสั่งซื้อยังไม่เพิ่มสต๊อกและยังไม่บันทึกรับของ เมื่อสินค้ามาถึงให้เปิดเมนู “รับสินค้าตามใบสั่งซื้อ”</p></div>
<ns-procurement
 search-url="/api/purchase-orders/search"
 submit-url="{{ $order ? url('/api/purchase-orders/'.$order->id.'?version='.$order->version) : url('/api/purchase-orders') }}"
 src="{{ $order ? url('/api/purchase-orders/form/'.$order->id) : url('/api/purchase-orders/form') }}"
 return-url="{{ route('ns.purchase-orders') }}">
<template v-slot:title>ชื่อใบสั่งซื้อ</template><template v-slot:save>บันทึกใบสั่งซื้อ</template>
<template v-slot:search-placeholder>SKU, บาร์โค้ด, ชื่อสินค้า</template>
</ns-procurement></div>
@endsection
