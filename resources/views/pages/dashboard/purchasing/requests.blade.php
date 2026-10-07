@extends('pages.dashboard.purchasing.layout')
@section('purchasing.body')
<form method="get" class="card actions"><label>สาขาที่ขอซื้อ<select name="branch_id">@foreach($branches as $b)<option value="{{ $b->id }}" @selected($branch==$b->id)>{{ $b->name }}</option>@endforeach</select></label><button>แสดงข้อมูลสาขา</button></form>
<details class="card"><summary>วิธีใช้งานและสิทธิ์</summary><p>1. ผู้มีสิทธิ์สร้างจัดซื้อเลือกสินค้า หน่วย จำนวน และเหตุผลแล้วส่งขอซื้อ<br>2. ผู้มีสิทธิ์แก้ไขจัดซื้ออนุมัติหรือปฏิเสธ พร้อมระบุเหตุผล<br>3. เปิดใบที่อนุมัติแล้ว พิมพ์รายการไปขอราคาจากผู้ขาย และบันทึกใบเสนอราคาแต่ละราย<br>4. เปรียบเทียบราคา วันส่ง และเครดิต ก่อนเลือกหนึ่งใบสร้างใบสั่งซื้อ ระบบไม่ส่งข้อความถึงผู้ขายให้อัตโนมัติ<br>5. รับสินค้าและยืนยันวางบิลผ่านขั้นตอนเดิม ใบขอซื้อและใบเสนอราคายังไม่เพิ่มสต๊อก</p><p>พนักงานเห็นเฉพาะสาขาที่ตนสังกัด ผู้ดูแลเลือกสาขาได้ สาขาใหม่ใช้ขั้นตอนเดียวกันหลังตั้งค่าสินค้า หน่วย และสิทธิ์ผู้ใช้</p></details>
@if(ns()->allowedTo('nexopos.create.procurements'))
<details class="card" @if($errors->any()) open @endif><summary>+ สร้างใบขอซื้อ</summary>
<form method="post" action="{{ route('ns.purchasing.requests.store') }}" id="purchase-request-form">@csrf<input type="hidden" name="request_key" value="{{ old('request_key',(string) Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="branch_id" value="{{ $branch }}">
<div class="grid"><label>ชื่อใบขอซื้อ<input name="name" required maxlength="150" value="{{ old('name') }}"></label><label>แผนก / ผู้ขอ<input name="department" maxlength="150" value="{{ old('department') }}"></label><label>ต้องการใช้วันที่<input type="date" name="needed_at" value="{{ old('needed_at') }}"></label><label class="full">เหตุผลขอซื้อ<textarea name="reason" required maxlength="2000">{{ old('reason') }}</textarea></label></div>
<div class="card"><label>ค้นหาสินค้าด้วยชื่อ SKU หรือบาร์โค้ด<input id="request-product-search" autocomplete="off"></label><button type="button" id="request-search-button">ค้นหาสินค้า</button><p id="request-search-status" role="status"></p><div id="request-search-results" class="search-results"></div></div>
<div id="request-lines"></div><p class="muted">เลือกหน่วยให้ตรงกับสิ่งที่ต้องการซื้อ เช่น ชิ้นหรือกล่อง ระบบไม่สั่งแทนกันอัตโนมัติ</p><button class="primary">ส่งใบขอซื้อ</button>
</form></details>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const results=document.getElementById('request-search-results'), lines=document.getElementById('request-lines'),status=document.getElementById('request-search-status'); let index=0;
 const add=(p,selected=null,qty=1)=>{const i=index++,row=document.createElement('div');row.className='item-row';const info=document.createElement('div');info.textContent=p.name+' / '+p.sku;const hidden=document.createElement('input');hidden.type='hidden';hidden.name=`lines[${i}][product_id]`;hidden.value=p.id;info.append(hidden);const label=document.createElement('label');label.textContent='หน่วย';const unit=document.createElement('select');unit.name=`lines[${i}][unit_id]`;unit.required=true;p.units.forEach(u=>{const o=document.createElement('option');o.value=u.id;o.textContent=u.name;o.selected=String(selected)===String(u.id);unit.append(o)});label.append(unit);const qlabel=document.createElement('label');qlabel.textContent='จำนวน';const input=document.createElement('input');input.type='number';input.min='0.00001';input.max='999999';input.step='0.00001';input.required=true;input.name=`lines[${i}][quantity]`;input.value=qty;qlabel.append(input);const del=document.createElement('button');del.type='button';del.textContent='ลบ';del.onclick=()=>row.remove();row.append(info,label,qlabel,del);lines.append(row)};
 document.getElementById('request-search-button').onclick=async()=>{results.replaceChildren();status.textContent='กำลังค้นหา…';try{const url=new URL(@json(route('ns.purchasing.products')));url.searchParams.set('branch_id',@json($branch));url.searchParams.set('search',document.getElementById('request-product-search').value);const r=await fetch(url,{headers:{Accept:'application/json'}}),data=await r.json();if(!r.ok)throw Error(data.message||'ค้นหาไม่สำเร็จ');status.textContent=data.length?'เลือกสินค้าที่ต้องการเพิ่ม':'ไม่พบสินค้า';data.forEach(p=>{const b=document.createElement('button');b.type='button';b.textContent=p.name+' / '+p.sku;b.onclick=()=>{add(p);results.replaceChildren();status.textContent='เพิ่มสินค้าแล้ว'};results.append(b)})}catch(e){status.textContent=e.message}};
 document.getElementById('request-product-search').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();document.getElementById('request-search-button').click()}});
 @foreach(old('lines',[]) as $line)
 @php($oldProduct=App\Models\Product::find($line['product_id']??0))
 @if($oldProduct)
 @php($oldProductData=['id'=>$oldProduct->id,'name'=>$oldProduct->name,'sku'=>$oldProduct->sku,'units'=>App\Models\Unit::where('group_id',$oldProduct->unit_group)->get(['id','name'])])
 add(@json($oldProductData),@json($line['unit_id']??null),@json($line['quantity']??1));
 @endif
 @endforeach
});
</script>
@endif
<section class="card"><h2>ใบขอซื้อของสาขา</h2><div class="table-wrap"><table><thead><tr><th>เอกสาร</th><th>แผนก</th><th>วันที่</th><th>สถานะ</th></tr></thead><tbody>@forelse($requests as $r)<tr><td><a href="{{ route('ns.purchasing.request',$r->id) }}">PR-{{ $r->id }} {{ $r->name }}</a></td><td>{{ $r->department ?: '—' }}</td><td>{{ $r->created_at }}</td><td>{{ ['submitted'=>'รออนุมัติ','approved'=>'อนุมัติ รอเทียบราคา','rejected'=>'ไม่อนุมัติ','ordered'=>'สร้างใบสั่งซื้อแล้ว'][$r->status] }}</td></tr>@empty<tr><td colspan="4">ยังไม่มีใบขอซื้อ</td></tr>@endforelse</tbody></table></div>{{ $requests->links() }}</section>
@endsection
