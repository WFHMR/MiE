@extends('layout.dashboard')
@section('layout.dashboard.body')
@include('../common/dashboard-header')
<div class="p-4" style="color:#173c40">
<h1 class="text-2xl font-bold">ใบส่งสินค้า / ใบฝากสินค้าชั่วคราวรายบิล</h1>
<p class="my-3">เลือกบิลของสาขาปัจจุบัน เอกสารใช้เลขอ้างอิงบิลเดิม การเปิดหรือพิมพ์ไม่ตัดสต๊อกหรือสร้างยอดขายซ้ำ และไม่ใช่หลักฐานรับชำระเงินหรือใบกำกับภาษี</p>
<form method="get" class="my-4"><label>เลขที่บิล <input class="border p-2" name="code" value="{{ request('code') }}" placeholder="เช่น 261001-038"></label><button class="border rounded p-2">ค้นหา</button></form>
<table class="w-full" style="background:white"><thead><tr><th class="p-2">เลขที่บิล</th><th>วันที่</th><th>ลูกค้า</th><th>ยอดรวม</th><th>เอกสาร</th></tr></thead><tbody>
@forelse($orders as $order)
<tr class="border"><td class="p-2">{{ $order->code }}</td><td>{{ $order->created_at }}</td><td>{{ $order->customer?->first_name }} {{ $order->customer?->last_name }}</td><td>{{ number_format($order->total,2) }}</td><td><a target="_blank" rel="noopener" href="{{ route('ns.dashboard.orders-invoice',['order'=>$order->id,'document'=>'temporary','print'=>1]) }}">เปิด / พิมพ์ A4</a></td></tr>
@empty
<tr><td colspan="5" class="p-4">ไม่พบบิลในสาขานี้ตามเลขที่ค้นหา</td></tr>
@endforelse
</tbody></table>
{{ $orders->links() }}
</div>
@endsection
