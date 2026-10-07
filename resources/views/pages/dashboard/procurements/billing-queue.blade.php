@extends('layout.dashboard')
@section('layout.dashboard.header')
<style>
.billing-review{max-width:1100px;margin:auto;padding:24px;line-height:1.7}.billing-review h1{font-size:24px;font-weight:700}.billing-review p{margin:10px 0}.billing-review .filters{display:flex;flex-wrap:wrap;gap:16px;align-items:end;margin:20px 0}.billing-review label{display:block}.billing-review select,.billing-review button{min-height:44px;border:1px solid currentColor;border-radius:6px;padding:8px 12px;background:transparent;color:inherit}.billing-review select{display:block;max-width:100%}.billing-review .document,.billing-review .notice{padding:18px;border:1px solid currentColor;border-radius:8px;margin:16px 0}.billing-review a{text-decoration:underline;text-underline-offset:3px}.billing-review .badge{display:inline-block;padding:2px 10px;border:1px solid currentColor;border-radius:12px;margin:6px 0}.billing-review .links{display:flex;flex-wrap:wrap;gap:12px}.billing-review .document{overflow-wrap:anywhere}@media(max-width:640px){.billing-review{padding:16px}.billing-review .filters>label{width:100%}.billing-review select{width:100%}}
</style>
@endsection
@section('layout.dashboard.body')
@include(Hook::filter('ns-dashboard-header-file','../common/dashboard-header'))
<main class="billing-review text-fontcolor">
<h1>{{ $title }}</h1><p>{{ $description }}</p>
<form method="get" class="filters">
<label>สาขา<select name="branch_id">@foreach($branches as $b)<option value="{{ $b->id }}" @selected($branch===$b->id)>{{ $b->name }}</option>@endforeach</select></label>
<label>สถานะ<select name="status"><option value="pending" @selected($status==='pending')>รอยืนยันวางบิล</option><option value="approved" @selected($status==='approved')>ยืนยันแล้ว / เข้าสต๊อกแล้ว</option><option value="all" @selected($status==='all')>ทุกสถานะ</option></select></label>
@if($orderId)<input type="hidden" name="purchase_order_id" value="{{ $orderId }}">@endif
<button>แสดงรายการ</button></form>
@if($orderId)<p>กำลังดูเอกสารจากใบสั่งซื้อ PO-{{ $orderId }} · <a href="{{ route('ns.billing-approval',['branch_id'=>$branch,'status'=>'all']) }}">ดูทุกใบสั่งซื้อของสาขานี้</a></p>@endif
<p class="notice">รายการที่ยืนยันแล้วจะอยู่ในสถานะ “ยืนยันแล้ว / เข้าสต๊อกแล้ว” ไม่อยู่ในรายการรอยืนยัน การเปิดดูประวัติไม่เพิ่มสต๊อกหรือบันทึกบัญชีซ้ำ</p>
<p>พบ {{ $documents->total() }} รายการ</p>
@forelse($documents as $d)
<article class="document"><strong>เอกสารรับเข้า #{{ $d->id }} · {{ $d->name }}</strong>
<p>ผู้ขาย {{ $d->provider?->first_name }} {{ $d->provider?->last_name }} · ยอด {{ number_format($d->cost,2) }} บาท</p>
@if($d->billing_approval_status==='approved' || $d->delivery_status==='stocked')<span class="badge">ยืนยันแล้ว / เข้าสต๊อกแล้ว</span>
@elseif($d->billing_approval_status==='pending')<span class="badge">ตรวจนับแล้ว รอยืนยันวางบิล</span>
@else<span class="badge">ยังไม่เข้าขั้นตอนยืนยันวางบิล</span>@endif
<p class="links">@if($d->purchase_order_id)<a href="{{ route('ns.purchase-orders.show',$d->purchase_order_id) }}">ดูใบสั่งซื้อ / ยอดคงค้าง</a>@endif
<a href="{{ ns()->route('ns.procurement-invoice',['procurement'=>$d->id]) }}">ดูเอกสารรับเข้า</a>
@if(ns()->allowedTo('nexopos.update.procurements'))<a href="{{ route('ns.purchase-paperwork',$d->id) }}">{{ $d->billing_approval_status==='pending' && $d->delivery_status!=='stocked' ? 'ตรวจเอกสารและยืนยันวางบิล' : 'ดูเลขที่ / วันที่เอกสาร' }}</a>@endif</p>
</article>
@empty<div class="notice">ไม่พบรายการตามสาขาและสถานะที่เลือก @if($status==='pending')<p>อาจยืนยันวางบิลแล้ว <a href="{{ route('ns.billing-approval',['branch_id'=>$branch,'status'=>'all','purchase_order_id'=>$orderId]) }}">ดูทุกสถานะ</a></p>@endif</div>@endforelse
{{ $documents->links() }}
</main>
@endsection
