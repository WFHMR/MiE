@extends('layout.dashboard')
@section('layout.dashboard.body')
@include(Hook::filter('ns-dashboard-header-file','../common/dashboard-header'))
<div class="p-4 text-primary"><h1 class="text-xl font-bold">รายการทดลอง / เอกสารเก่า</h1>
<p class="my-4">เก็บเข้ารายการทดลองเพื่อซ่อนจากรายการใช้งาน เอกสาร ประวัติ สต๊อก และบัญชียังคงเดิม การคืนรายการไม่รับสินค้าเข้าสต๊อก</p>
@if(session('success'))<p>{{ session('success') }}</p>@endif
@if($errors->any())<p>{{ $errors->first() }}</p>@endif
@foreach($documents as $document)
<div class="border rounded p-4 my-3"><strong>#{{ $document->id }} {{ $document->name }}</strong>
<p>{{ $document->trial_archived_at ? 'อยู่ในรายการทดลอง' : 'ยังอยู่ในรายการใช้งาน' }} · {{ $document->trial_archived_at }}</p>
<p><a class="underline" href="{{ ns()->route('ns.procurement-invoice',['procurement'=>$document->id]) }}">ดูเอกสารเดิม</a></p>
<form method="post" action="{{ route('ns.purchase-trials.archive',$document->id) }}">@csrf
<input type="hidden" name="action" value="{{ $document->trial_archived_at ? 'restore' : 'archive' }}">
<label><input type="checkbox" name="acknowledge" value="1" required> ยืนยันจัดเก็บเอกสารนี้โดยไม่เปลี่ยนสต๊อกหรือบัญชี</label>
<button class="border rounded p-2 m-2">{{ $document->trial_archived_at ? 'คืนรายการ' : 'เก็บเข้ารายการทดลอง' }}</button>
</form></div>
@endforeach
{{ $documents->links() }}</div>
@endsection
