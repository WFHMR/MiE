@extends('layout.dashboard')
@section('layout.dashboard.body')
@include(Hook::filter('ns-dashboard-header-file','../common/dashboard-header'))
<div class="text-primary" style="padding:20px;max-width:1200px;margin:auto">
<h1 style="font-size:24px;font-weight:bold">สรุปวางบิลซัพพลายเออร์</h1>
<p style="margin:12px 0">เฉพาะเอกสารยืนยันรับเข้าแล้ว ไม่รวมรายการทดลอง เลือกทีละสาขาและซัพพลายเออร์</p>
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
<form method="get" style="display:flex;flex-wrap:wrap;gap:14px;align-items:end;margin-bottom:20px">
<label>สาขา<select name="branch_id" style="display:block;padding:10px;border:1px solid;max-width:260px">
@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($branchId===$branch->id)>{{ $branch->name }}</option>@endforeach
@if(ns()->hasRole('admin'))<option value="0" @selected($branchId===0)>เอกสารเก่าไม่ระบุสาขา</option>@endif</select></label>
<label>ซัพพลายเออร์<select required name="provider_id" style="display:block;padding:10px;border:1px solid;max-width:320px"><option value="">เลือกซัพพลายเออร์</option>@foreach($providers as $item)<option value="{{ $item->id }}" @selected(($data['provider_id']??'')==$item->id)>{{ $item->code }} {{ $item->first_name }} {{ $item->last_name }}</option>@endforeach</select></label>
<label>ใช้วันที่<select name="date_field" style="display:block;padding:10px;border:1px solid">@foreach($dateLabels as $key=>$label)<option value="{{ $key }}" @selected($dateField===$key)>{{ $label }}</option>@endforeach</select></label>
<label>ตั้งแต่<input type="date" required name="from" value="{{ $data['from']??date('Y-m-01') }}" style="display:block;padding:10px;border:1px solid"></label>
<label>ถึง<input type="date" required name="to" value="{{ $data['to']??date('Y-m-t') }}" style="display:block;padding:10px;border:1px solid"></label>
<label>ชำระเงิน<select name="payment" style="display:block;padding:10px;border:1px solid">@foreach(['unpaid'=>'ยังไม่ชำระ','paid'=>'ชำระแล้ว','all'=>'ทั้งหมด'] as $key=>$label)<option value="{{ $key }}" @selected(($data['payment']??'unpaid')===$key)>{{ $label }}</option>@endforeach</select></label>
<button name="format" value="html" style="padding:10px;border:1px solid">แสดงรายการ</button><button name="format" value="xlsx" style="padding:10px;border:1px solid">ดาวน์โหลด Excel</button><button name="format" value="print" formtarget="_blank" style="padding:10px;border:1px solid">ใบสรุปสำหรับพิมพ์</button>
</form>
<p>เอกสารที่ไม่มีวันที่ตามตัวกรองจะไม่แสดง กรุณาเติมข้อมูลผ่าน “แก้ไขเลขที่ / วันที่เอกสาร” ก่อน</p>
@if($provider)<h2 style="font-weight:bold;margin:16px 0">{{ $provider->first_name }} {{ $provider->last_name }} — {{ $branchName }}</h2><div style="overflow:auto">@include('pages.dashboard.procurements.supplier-billing-table')</div>@endif
</div>
@endsection
