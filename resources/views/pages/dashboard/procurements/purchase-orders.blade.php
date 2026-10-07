@extends('layout.dashboard')
@section('layout.dashboard.header')
<style>.po-page{padding:16px;max-width:1200px;margin:auto}.po-page h1{font-size:24px;font-weight:bold}.po-page h2{font-size:20px;font-weight:bold;margin:16px 0}.po-page p{margin:10px 0}.po-page input,.po-page button,.po-page select{max-width:100%;padding:10px;min-height:44px;border:1px solid #84949b;border-radius:5px;background:transparent;color:inherit}.po-page a{text-decoration:underline}.po-page .card{border:1px solid #84949b;padding:14px;margin:12px 0;border-radius:8px;overflow-wrap:anywhere}.po-page .grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.po-page label{display:block}.po-page label input,.po-page label select{display:block;width:100%;box-sizing:border-box}.po-page .primary{background:#17675e;color:white}.po-page .error{border:2px solid #c13232;padding:10px}.po-page .success{border:2px solid #21834a;padding:10px}.po-page .quantity{width:150px}.po-page .line{display:flex;gap:14px;flex-wrap:wrap;justify-content:space-between;align-items:center}@media(max-width:700px){.po-page .grid{grid-template-columns:1fr}}</style>
@endsection
@section('layout.dashboard.body')
@include(Hook::filter('ns-dashboard-header-file','../common/dashboard-header'))
<div class="po-page text-fontcolor"><h1>{{ $title }}</h1><p>{{ $description }}</p>
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(session('success'))<p class="success" role="status">{{ session('success') }}</p>@endif
@unless(isset($order))
<p><a href="{{ route('ns.purchase-orders.create') }}">สร้างใบสั่งซื้อ</a> · <a href="{{ ns()->route('ns.procurement-list') }}">เอกสารรับเข้า / เอกสารเดิม</a></p>
@forelse($orders as $po)<div class="card"><a href="{{ route('ns.purchase-orders.show',$po->id) }}">PO-{{ $po->id }} — {{ $po->name }}</a><p>เลขที่อ้างอิง {{ $po->reference ?: '—' }} · สาขา #{{ $po->branch_id }} · {{ ['pending'=>'รอรับสินค้า','partial'=>'รับบางส่วน','received'=>'ตรวจนับครบแล้ว'][$po->status] }}</p></div>@empty<p>ยังไม่มีใบสั่งซื้อในขั้นตอนใหม่ เอกสารเดิมยังอยู่ในเมนูเอกสารรับเข้า / เอกสารเดิม</p>@endforelse
{{ $orders->links() }}
@else
<p><a href="{{ route('ns.purchase-orders') }}">กลับรายการใบสั่งซื้อ</a>@if(!$purchaseRequest && $order->status==='pending' && ns()->allowedTo('nexopos.update.procurements')) · <a href="{{ route('ns.purchase-orders.edit',$order->id) }}">แก้ไขใบสั่งซื้อก่อนรับ</a>@endif</p>
@if($purchaseRequest)<p>สร้างจาก <a href="{{ route('ns.purchasing.request',$purchaseRequest->id) }}">ใบขอซื้อ PR-{{ $purchaseRequest->id }} / ใบเสนอราคาที่อนุมัติ</a> เก็บราคาและรายการตามที่อนุมัติไว้</p>@endif
<h2>{{ $order->name }}</h2><p>สาขา {{ $branch->name }} · เลขที่ใบสั่งซื้อ {{ $order->reference ?: 'PO-'.$order->id }} · กำหนดส่ง {{ $order->expected_at ?: '—' }}</p>
<p>สถานะ: {{ ['pending'=>'รอรับสินค้า','partial'=>'รับบางส่วน','received'=>'ตรวจนับครบแล้ว'][$order->status] }}</p>
<form method="post" action="{{ route('ns.purchase-orders.receive',$order->id) }}">@csrf<input type="hidden" name="version" value="{{ $order->version }}">
@foreach($order->lines as $index=>$line)<div class="card line"><div><strong>{{ $line['name'] }}</strong><p>หน่วย {{ $line['unit_name'] }} · สั่ง {{ $line['quantity'] }} · รับแล้ว {{ $line['received'] }} · คงค้าง {{ round($line['quantity']-$line['received'],5) }}</p><p>ราคาต่อหน่วย {{ number_format($line['purchase_price'],2) }} บาท</p></div>
@if($order->status!=='received' && ns()->allowedTo(['nexopos.create.procurements']) && ns()->allowedTo('nexopos.update.procurements'))<label>จำนวนที่ตรวจนับจริงครั้งนี้<input class="quantity" type="number" min="0" max="{{ round($line['quantity']-$line['received'],5) }}" step="0.00001" name="quantities[{{ $index }}]" value="{{ old('quantities.'.$index,0) }}"></label>@endif
</div>@endforeach
@if($order->status!=='received' && ns()->allowedTo('nexopos.create.procurements') && ns()->allowedTo('nexopos.update.procurements'))
<h2>ข้อมูลรับสินค้าและเอกสารผู้ขาย</h2><p>กรอกเฉพาะจำนวนที่ได้รับจริง ช่องเป็นศูนย์คือยังไม่รับรายการนั้น สามารถทยอยรับจนกว่าจะครบ</p>
<div class="grid">
@foreach(['received_at'=>'วันที่รับสินค้า','invoice_reference'=>'เลขที่ใบกำกับภาษีผู้ขาย','invoice_date'=>'วันที่ใบกำกับภาษี','delivery_note_number'=>'เลขที่ใบส่งสินค้า','delivery_note_date'=>'วันที่ใบส่งสินค้า','billing_note_number'=>'เลขที่ใบวางบิล','billing_note_date'=>'วันที่ใบวางบิล','contact_person'=>'ผู้ติดต่อ / ผู้รับเรื่อง','credit_days'=>'เครดิต (วัน)','due_date'=>'ครบกำหนดชำระ'] as $field=>$label)
<label>{{ $label }}<input name="{{ $field }}" type="{{ str_ends_with($field,'_date') || $field==='received_at' ? 'date' : ($field==='credit_days' ? 'number' : 'text') }}" value="{{ old($field,$field==='received_at'?now()->format('Y-m-d'):'') }}" @if($field==='received_at') required @endif @if($field==='credit_days') min="0" max="3650" @endif></label>
@endforeach

</div><div class="card"><p>ตรวจสาขา จำนวนรับ และหน่วยสินค้าให้ครบ การบันทึกครั้งนี้ยังไม่เพิ่มสต๊อก ฝ่ายวางบิลต้องตรวจเอกสารและกดยืนยันอีกครั้ง</p><label><input style="display:inline;width:auto" type="checkbox" name="acknowledge" value="1" required> ยืนยันว่าตรวจนับสินค้าจริงและตรวจเอกสารแล้ว</label><p><button class="primary">บันทึกยอดตรวจนับ ส่งให้ฝ่ายวางบิล</button></p></div>
@endif
</form><h2>ประวัติตรวจนับสินค้า</h2><p><a href="{{ route('ns.billing-approval',['branch_id'=>$order->branch_id,'status'=>'all','purchase_order_id'=>$order->id]) }}">ตรวจรับ / ยืนยันวางบิลของสาขานี้</a></p>
@forelse($order->receipts as $receipt)<p><a href="{{ ns()->route('ns.procurement-invoice',['procurement'=>$receipt['id']]) }}">เอกสารรับเข้า #{{ $receipt['id'] }}</a> · <a href="{{ route('ns.purchase-paperwork',$receipt['id']) }}">แก้ไขเลขที่ / วันที่เอกสาร</a> · {{ $receipt['at'] }} · ผู้รับ #{{ $receipt['by'] }}</p>@empty<p>ยังไม่รับสินค้า</p>@endforelse
@endif
</div>
@endsection
