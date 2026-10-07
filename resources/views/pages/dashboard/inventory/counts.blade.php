@extends('layout.dashboard')
@section('layout.dashboard.header')
<style>
.stock-count{padding:16px;max-width:1200px;margin:auto}.stock-count h1{font-size:24px;font-weight:bold}.stock-count h2{font-size:19px;font-weight:bold;margin:16px 0}.stock-count p{margin:8px 0}.stock-count input,.stock-count select,.stock-count button{padding:10px;border:1px solid #88939e;border-radius:6px;max-width:100%;min-height:44px;color:inherit;background:transparent}.stock-count button,.stock-count a{cursor:pointer;text-decoration:underline}.stock-count .row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:12px 0}.stock-count .card{border:1px solid #88939e;border-radius:8px;padding:12px;margin:12px 0;overflow-wrap:anywhere}.stock-count .error{border:2px solid #c13232;padding:12px}.stock-count .success{border:2px solid #21834a;padding:12px}.stock-count .line-head{font-weight:bold}.stock-count small{display:block}.stock-count .quantity{width:150px}.stock-count table{width:100%;border-collapse:collapse}.stock-count td,.stock-count th{padding:8px;text-align:left;border-bottom:1px solid #aaa}.stock-count .table-scroll{overflow-x:auto}.stock-count .primary{background:#17675e;color:white;border-color:#17675e}.stock-count label{display:inline-block}.stock-count .muted{font-size:14px}
</style>
@endsection
@section('layout.dashboard.body')
@include(Hook::filter('ns-dashboard-header-file', '../common/dashboard-header'))
<div class="stock-count text-fontcolor">
<h1>{{ $title }}</h1><p>{{ $description }}</p>
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(session('success'))<p class="success" role="status">{{ session('success') }}</p>@endif
@unless(isset($count))
<h2>เปิดใบนับสินค้า</h2>
<form method="post" action="{{ route('ns.dashboard.inventory.counts.create') }}">@csrf
<div class="row"><label>สาขา <select name="branch_id" required>@foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></label>
<label>ชื่อใบนับ / พื้นที่ตรวจ <input name="title" maxlength="150" required value="{{ old('title') }}" placeholder="เช่น ชั้นวางเครื่องดื่ม"></label><button class="primary">เปิดใบนับ</button></div></form>
@if($branches->isEmpty())<p>บัญชีนี้ยังไม่มีสาขาที่ตรวจนับได้ กรุณาให้ผู้ดูแลผูกสาขากับบัญชี</p>@endif
<p>การเปิดใบนับและบันทึกจำนวนยังไม่เปลี่ยนสต๊อก ผู้ได้รับสิทธิ์ยืนยันจึงจะปรับยอดจริงได้</p>
<h2>ใบนับและประวัติของสาขาที่เข้าถึงได้</h2>
@forelse($documents as $document)<div class="card"><a href="{{ route('ns.dashboard.inventory.counts.show', $document->id) }}">#{{ $document->id }} — {{ $document->title }}</a><p>สาขา #{{ $document->branch_id }} · {{ $document->status === 'confirmed' ? 'ยืนยันแล้ว' : 'กำลังนับ' }} · {{ $document->created_at }}</p></div>@empty<p>ยังไม่มีใบนับ</p>@endforelse
{{ $documents->links() }}
@else
<a href="{{ route('ns.dashboard.inventory.counts') }}">กลับรายการใบนับ</a>
<p><strong>สาขา: {{ $branch->name }}</strong> · {{ $count->status === 'confirmed' ? 'ยืนยันปรับสต๊อกแล้ว' : 'กำลังนับ — ยังไม่ปรับสต๊อก' }}</p>
<p class="muted">ผู้เปิดใบนับ #{{ $count->author_id }} · {{ $count->created_at }}@if($count->confirmed_at) · ผู้ยืนยัน #{{ $count->confirmed_by }} · {{ $count->confirmed_at }}@endif</p>
@if($count->status === 'draft')
<div class="card"><p>1. ค้นหาแล้วเพิ่มสินค้า → 2. นับและบันทึกจำนวนแต่ละรายการ → 3. ตรวจยอดต่างและยืนยัน</p><p>นับตามหน่วยที่แสดง หากเก็บสต๊อกเป็นชิ้นให้รวมจำนวนในแพ็ค/ลังเป็นชิ้นด้วย ระหว่างนับควรหยุดเคลื่อนย้ายสินค้ารายการนั้น หากยอดเปลี่ยนระบบจะให้ตรวจนับใหม่</p>
<form method="get"><div class="row"><label>ชื่อสินค้า / SKU / บาร์โค้ด <input name="q" value="{{ $query }}" maxlength="100" required></label><button>ค้นหา</button></div></form>
@if($query !== '')
@forelse($matches as $match)<form method="post" action="{{ route('ns.dashboard.inventory.counts.update', $count->id) }}">@csrf<input type="hidden" name="version" value="{{ $count->version }}"><input type="hidden" name="action" value="add"><input type="hidden" name="row_id" value="{{ $match->id }}"><div class="row"><span>{{ $match->product->name }} · {{ $match->unit->name }} · {{ $match->barcode }}</span><button>เพิ่มเข้ารายการนับ</button></div></form>@empty<p>ไม่พบหน่วยที่เก็บสต๊อกในสาขานี้ ลองค้นด้วยชื่อสินค้า</p>@endforelse
<p class="muted">แสดงสูงสุด 50 ผลลัพธ์ กรุณาระบุคำค้นให้เจาะจง</p>
@endif</div>
@endif
<h2>รายการตรวจนับ ({{ count($count->lines) }})</h2>
@forelse($count->lines as $line)
<div class="card"><p class="line-head">{{ $line['name'] }} · หน่วย {{ $line['unit'] }}</p><p>บาร์โค้ด {{ $line['barcode'] ?: '—' }} · ยอดระบบเมื่อเริ่มนับ {{ number_format($line['before'], 5) }}</p>
@if($count->status === 'draft')
<form method="post" action="{{ route('ns.dashboard.inventory.counts.update', $count->id) }}">@csrf<input type="hidden" name="version" value="{{ $count->version }}"><input type="hidden" name="row_id" value="{{ $line['id'] }}"><div class="row"><label>จำนวนที่นับได้ <input class="quantity" type="number" min="0" max="999999999" step="0.00001" name="quantity" value="{{ $line['counted'] }}"></label><button name="action" value="save">บันทึกยอดนับ</button><button name="action" value="remove" formnovalidate>นำออกจากใบนับ</button></div></form>
@endif
<p>ยอดนับที่บันทึก: <strong>{{ $line['counted'] === null ? 'ยังไม่ได้นับ' : number_format($line['counted'], 5) }}</strong> · ผลต่าง: <strong>{{ $line['counted'] === null ? '—' : number_format($line['counted'] - $line['before'], 5) }}</strong></p>
@if($line['counted_at'])<small>บันทึกโดย #{{ $line['counted_by'] }} · {{ $line['counted_at'] }}</small>@endif
</div>
@empty<p>ยังไม่มีรายการ สินค้าที่ไม่เพิ่มเข้าใบนับจะไม่ถูกปรับยอด</p>@endforelse
@if($count->status === 'draft' && ns()->allowedTo('nexopos.stock-counts.confirm'))
<form method="post" action="{{ route('ns.dashboard.inventory.counts.update', $count->id) }}">@csrf<input type="hidden" name="version" value="{{ $count->version }}"><input type="hidden" name="action" value="confirm"><div class="card"><p>ยืนยันจะตั้งยอดสต๊อกของรายการข้างต้นในสาขา {{ $branch->name }} ตามยอดนับที่บันทึกไว้ ตรวจยอดนับและผลต่างให้ครบก่อนกด</p><label><input type="checkbox" name="acknowledge" value="1" required> ตรวจสอบสาขา หน่วย และยอดนับที่บันทึกแล้ว</label><div class="row"><button class="primary" id="confirm-stock-count">ยืนยันปรับสต๊อกจริง</button></div></div></form>
@elseif($count->status === 'draft')<p>บัญชีนี้กรอกยอดนับได้ แต่ยังไม่มีสิทธิ์ยืนยันปรับสต๊อก</p>@endif
@endunless
</div>
@endsection

@section('layout.dashboard.footer')
@parent
<script>
document.querySelectorAll('.stock-count input[name="quantity"]').forEach(input => {
    input.addEventListener('input', () => {
        const button = document.getElementById('confirm-stock-count');
        if (button) { button.disabled = true; button.textContent = 'กรุณาบันทึกยอดนับที่แก้ก่อนยืนยัน'; }
    });
});
</script>
@endsection