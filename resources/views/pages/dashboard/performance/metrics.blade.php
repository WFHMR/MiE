@extends('pages.dashboard.performance.layout')
@section('performance.body')
@if(ns()->hasRole('admin'))
<form class="card" method="post" action="{{ route('ns.performance.store',['action'=>'metric']) }}">@csrf<h2>เพิ่มตัวชี้วัด</h2><div class="grid"><label>ชื่อตัวชี้วัด<input name="name" maxlength="150" required value="{{ old('name') }}"></label><label>หน่วย เช่น บาท / ครั้ง / %<input name="unit" maxlength="40" required value="{{ old('unit') }}"></label><label>ทิศทาง<select name="direction"><option value="higher">ยิ่งมากยิ่งดี</option><option value="lower">ยิ่งน้อยยิ่งดี</option></select></label></div><label>นิยามและแหล่งข้อมูลที่ใช้ประเมิน<textarea name="description" maxlength="2000" required>{{ old('description') }}</textarea></label><p class="muted">เช่น ยอดขายสุทธิของพนักงานจากรายงานยอดขาย หรือจำนวนข้อผิดพลาดที่ตรวจพบจากสมุดบันทึก</p><button class="primary">สร้างตัวชี้วัด</button></form>
@endif

<section class="card"><h2>ตัวชี้วัดกลางสำหรับทุกสาขา</h2><div class="table-wrap"><table><thead><tr><th>ชื่อ</th><th>หน่วย</th><th>ทิศทาง</th><th>นิยาม</th></tr></thead><tbody>@forelse($metrics as $m)<tr><td>{{ $m->name }}</td><td>{{ $m->unit }}</td><td>{{ $m->direction==='higher'?'ยิ่งมากยิ่งดี':'ยิ่งน้อยยิ่งดี' }}</td><td>{{ $m->description }}</td></tr>@empty<tr><td colspan="4">ยังไม่มีตัวชี้วัด ผู้ดูแลสามารถสร้างได้ด้านบน</td></tr>@endforelse</tbody></table></div></section>
@endsection
