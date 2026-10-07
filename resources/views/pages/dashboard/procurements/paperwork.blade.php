@extends('layout.dashboard')
@section('layout.dashboard.body')
@include(Hook::filter('ns-dashboard-header-file','../common/dashboard-header'))
<div class="p-4 text-primary" style="max-width:900px;margin:auto;width:100%">
<h1 class="text-xl font-bold">{{ $document->billing_approval_status === 'pending' ? 'ตรวจเอกสาร / ยืนยันวางบิล' : 'ข้อมูลเอกสารรับเข้า' }} #{{ $document->id }}</h1><p class="my-3">{{ $document->name }} · แก้เลขที่และวันที่เอกสารได้ โดยจำนวนสินค้า สต๊อก และสถานะรับเข้าคงเดิม</p>
@if(session('success'))<p class="my-3">{{ session('success') }}</p>@endif
@if($errors->any())<p class="my-3">{{ $errors->first() }}</p>@endif
<p class="my-3"><strong>สถานะ: {{ $document->billing_approval_status === 'pending' ? 'ตรวจนับแล้ว รอยืนยันวางบิล — ยังไม่เข้าสต๊อก' : ($document->delivery_status === 'stocked' ? 'เข้าสต๊อกแล้ว' : 'รอรับเข้า') }}</strong></p>
@if($document->billing_approval_status)
<div style="overflow-x:auto"><table class="w-full"><thead><tr><th>สินค้า</th><th>หน่วย</th><th>จำนวนตรวจนับ</th><th>รวมเงิน</th></tr></thead><tbody>@foreach($document->products as $line)<tr><td>{{ $line->name }}</td><td>{{ \App\Models\Unit::find($line->unit_id)?->name }}</td><td>{{ $line->quantity }}</td><td>{{ number_format($line->total_purchase_price,2) }}</td></tr>@endforeach</tbody></table></div><p>ยอดรวม {{ number_format($document->cost,2) }} บาท</p>
@endif
<form method="post" action="{{ route('ns.purchase-paperwork.save',$document->id) }}">@csrf<input type="hidden" name="version" value="{{ $version }}">
@foreach($fields as $key=>$label)<label class="block my-3">{{ $label }}
<input class="block w-full border rounded p-2" name="{{ $key }}" type="{{ str_ends_with($key,'_date') ? 'date' : ($key==='credit_days' ? 'number' : 'text') }}" value="{{ old($key, $document->$key) }}" @if($key==='credit_days') min="0" max="3650" @endif></label>@endforeach
<button class="border rounded p-3 my-3">บันทึกข้อมูลเอกสาร</button>
</form><a class="underline" href="{{ ns()->route('ns.procurement-invoice',['procurement'=>$document->id]) }}">ดูใบรับของ</a>
@if($document->billing_approval_status === 'pending')
<form method="post" action="{{ route('ns.billing-approval.confirm',$document->id) }}" class="border rounded p-3 my-3">@csrf<input type="hidden" name="version" value="{{ $version }}">
<p>บันทึกข้อมูลเอกสารด้านบนก่อน จากนั้นตรวจยอดรับจริงและยอดเงิน หากไม่ระบุวันครบกำหนด ระบบใช้{{ $document->receipt_source === 'no_bill' ? 'วันที่รับสินค้าหากยังไม่มีวันที่ใบกำกับภาษี' : 'วันที่ใบกำกับภาษี' }}บวกเครดิต (ไม่ระบุเครดิต = 0 วัน) การยืนยันจะเพิ่มสต๊อกตามจำนวนนี้ครั้งเดียว และตั้งยอดค้างชำระ</p>
<label class="block my-3"><input type="checkbox" name="acknowledge" value="1" required> ตรวจจำนวนรับและเอกสารครบแล้ว ยืนยันเข้าสต๊อก</label><button class="border rounded p-3">ยืนยันวางบิลและเข้าสต๊อก</button></form>
@elseif($document->billing_approval_status === 'approved')<p>ยืนยันโดย #{{ $document->billing_approved_by }} เมื่อ {{ \Carbon\Carbon::parse($document->billing_approved_at)->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</p>@endif
<p><a href="{{ route('ns.billing-approval',['branch_id'=>$document->branch_id]) }}">กลับรายการรอยืนยันวางบิล</a> · <a href="{{ route('ns.supplier-billing',['branch_id'=>$document->branch_id,'provider_id'=>$document->provider_id,'date_field'=>'due_date','from'=>\Carbon\Carbon::parse($document->due_date ?: $document->invoice_date ?: $document->created_at)->startOfMonth()->format('Y-m-d'),'to'=>\Carbon\Carbon::parse($document->due_date ?: $document->invoice_date ?: $document->created_at)->endOfMonth()->format('Y-m-d')]) }}">สรุปวางบิล</a></p>
</div>
@endsection
