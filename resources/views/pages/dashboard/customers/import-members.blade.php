@extends('layout.dashboard')
@section('layout.dashboard.body')
@include(Hook::filter('ns-dashboard-header-file', '../common/dashboard-header'))
<div class="p-4 text-fontcolor" style="max-width:1200px;margin:auto;overflow-wrap:anywhere">
<h1 class="text-2xl font-bold">นำเข้ารายชื่อสมาชิก</h1>
@if($errors->any())<div role="alert" class="p-4 border">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(session('success'))<p role="status" class="p-4 border">{{ session('success') }}</p>@endif
<details open class="border p-4 my-4"><summary class="font-bold">วิธีนำเข้าสมาชิก</summary>
<ol style="list-style:decimal;padding-left:24px">
<li>ดาวน์โหลด <a class="underline" href="{{ route('ns.members.template') }}">ไฟล์ตัวอย่าง CSV</a> หรือใช้ Excel ที่มีคอลัมน์ <strong>ชื่อ</strong> และ <strong>เบอร์โทร</strong> ในชีตแรก</li>
<li>ตั้งคอลัมน์เบอร์โทรและรหัสสมาชิกเป็นข้อความเพื่อเก็บเลข 0 นำหน้า ใส่รหัสสมาชิกและคะแนนเดิมได้ ถ้าไม่มีคะแนนให้เว้นว่างหรือใส่ 0</li>
<li>เลือกสาขาที่นำเข้าเพื่อเก็บประวัติ และเลือกกลุ่มลูกค้า คะแนนตั้งต้นใช้ร่วมทุกสาขา กลุ่มใช้กับลูกค้าที่สร้างใหม่เท่านั้น</li>
<li>กดตรวจข้อมูล อ่านพรีวิวทุกรายการ แล้วจึงยืนยัน หากมีข้อมูลผิดหรือซ้ำ ระบบจะยังไม่นำเข้า ให้แก้ไฟล์และอัปโหลดใหม่</li>
<li>ลูกค้าเดิมที่ชื่อและเบอร์ตรงกันจะใช้ประวัติเดิม ไม่แก้ชื่อหรือข้อมูลเก่า หากมีสมาชิกในระบบคะแนนที่สาขาใดแล้วจะไม่นำเข้าหรือเติมคะแนนตั้งต้นซ้ำ ให้ใช้สมาชิกเดิมได้เลยทุกสาขา</li>
</ol><p class="mt-2">สูงสุด 5,000 รายการ / 4 MB ต่อครั้ง พรีวิวมีอายุ 24 ชั่วโมง การนำเข้าสมาชิกไม่เปิดการสะสมหรือแลกคะแนนโดยอัตโนมัติ</p></details>
<form method="post" enctype="multipart/form-data" action="{{ route('ns.members.preview') }}" class="border p-4 my-4">@csrf
<label class="block my-2">สาขาที่นำเข้า <select name="branch_id" required class="border p-2">@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int)session('selected_branch_id') === $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
<label class="block my-2">กลุ่มลูกค้าใหม่ <select name="group_id" required class="border p-2">@foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach</select></label>
<label class="block my-2">ไฟล์รายชื่อ <input type="file" name="file" required accept=".csv,.xlsx,.xls" style="max-width:100%"></label>
<button class="border p-3" type="submit">ตรวจข้อมูลก่อนนำเข้า</button></form>
@if($batch)
<h2 class="text-xl font-bold">{{ $batch->status === 'confirmed' ? 'รายการที่นำเข้าแล้ว' : 'พรีวิว — ยังไม่นำเข้าจริง' }}</h2>
<p>สาขา: {{ $branches->firstWhere('id', $batch->branch_id)?->name }} · จำนวน {{ count($batch->rows) }} รายการ</p>
<div style="overflow-x:auto" class="my-4"><table class="w-full"><thead><tr><th>แถว</th><th>ชื่อ</th><th>เบอร์โทร</th><th>รหัสสมาชิก</th><th>คะแนนตั้งต้น</th><th>ผลตรวจ</th></tr></thead><tbody>
@foreach($batch->rows as $row)<tr class="border-b"> <td class="p-2">{{ $row['line'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['phone'] }}</td><td>{{ $row['code'] }}</td><td>{{ number_format($row['points']) }}</td><td>{{ implode(' / ', $row['errors']) ?: ($row['customer_id'] ? 'เชื่อมลูกค้าเดิม' : 'สร้างลูกค้าใหม่') }}</td></tr>@endforeach
</tbody></table></div>
@if($batch->status === 'preview' && !collect($batch->rows)->contains(fn($r) => count($r['errors']) > 0))
<form method="post" action="{{ route('ns.members.confirm', $batch->id) }}">@csrf
<label class="block my-3"><input type="checkbox" name="acknowledge" value="1" required> ตรวจสอบสาขา รายชื่อ และคะแนนตั้งต้นครบแล้ว ยืนยันนำเข้ารายการทั้งหมด</label><button class="border p-3">ยืนยันนำเข้า</button></form>
@elseif($batch->status === 'preview')<p role="alert">พบรายการที่ต้องแก้ไข กรุณาแก้ไฟล์แล้วอัปโหลดใหม่ ยังไม่นำเข้าข้อมูลใด ๆ</p>@endif
@endif
</div>
@endsection
