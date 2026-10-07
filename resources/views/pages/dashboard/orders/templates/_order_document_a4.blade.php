@php
 $customer=$order->customer;
 $address=fn($a)=>collect(['address_1','address_2','city','pobox','country'])->map(fn($key)=>trim((string)data_get($a,$key)))->filter()->implode(' ');
 $buyerName=trim((string)data_get($order->billing_address,'company')) ?: trim((string)data_get($order->billing_address,'first_name').' '.(string)data_get($order->billing_address,'last_name'));
 $buyerName=$buyerName ?: trim(($customer?->first_name ?? '').' '.($customer?->last_name ?? ''));
 $phone=data_get($order->billing_address,'phone') ?: $customer?->phone;
 $sellerName=ns()->option->get('ns_thai_tax_registered_name') ?: ns()->option->get('ns_store_name');
 $sellerAddress=$branch?->address ?: ns()->option->get('ns_thai_tax_registered_address');
 $sellerPhone=$branch?->phone ?: ns()->option->get('ns_store_phone');
 $paid=$order->payment_status===\App\Models\Order::PAYMENT_PAID;
 $documentTitle=$paid ? 'ใบส่งสินค้า / ใบเสร็จรับเงิน' : 'ใบส่งสินค้า / ใบแจ้งหนี้';
 $temporary=request()->query('document') === 'temporary';
 if($temporary) $documentTitle='ใบส่งสินค้าชั่วคราว / ใบฝากสินค้าชั่วคราว';
 $due=max(0,(float)$order->total-(float)$order->tendered);
 $money=fn($value)=>number_format((float)$value,2);
 $quantity=fn($value)=>rtrim(rtrim(number_format((float)$value,5,'.',''),'0'),'.');
@endphp
<style>
.order-a4{box-sizing:border-box;width:194mm;margin:0 auto;background:#fff;color:#111;font-family:Sarabun,Tahoma,sans-serif;font-size:10pt;line-height:1.4}.order-a4 *{box-sizing:border-box}.order-a4.temporary{min-height:270mm;display:flex;flex-direction:column}.order-a4.temporary > *{flex-shrink:0}.order-a4.temporary .closing{margin-top:auto}.order-a4 table{width:100%;border-collapse:collapse;table-layout:fixed}.order-a4 td,.order-a4 th{vertical-align:top;overflow-wrap:anywhere}.order-a4 .frame{border:1px solid #333;padding:7px}.order-a4 .title{font-size:16pt;font-weight:bold;text-align:center;border:1px solid #222;padding:8px}.order-a4 .items td,.order-a4 .items th{border:1px solid #333;padding:5px}.order-a4 .items th{background:#eee;text-align:center}.order-a4 .right{text-align:right}.order-a4 .small{font-size:9pt}.order-a4 .closing{break-inside:avoid}.order-a4 .signature{height:90px;text-align:center;vertical-align:bottom;padding:12px;border:1px solid #333}.order-a4 .warning{border:2px solid #111;padding:6px;margin:6px 0;text-align:center;font-weight:bold}@media print{.order-a4 thead{display:table-header-group}.order-a4 tr{break-inside:avoid}.order-a4 .closing{break-inside:avoid}}
</style>
<article class="order-a4 {{ $temporary ? 'temporary' : '' }}">
<table style="margin-bottom:8px"><tr><td style="width:57%;padding-right:12px"><strong style="font-size:15pt">{{ $sellerName }}</strong><div style="white-space:pre-line">{{ $sellerAddress }}</div>@if($sellerPhone)<div>โทร. {{ $sellerPhone }}</div>@endif<div>สาขา {{ $branch?->name ?: 'ไม่ระบุสาขา' }} @if($branch?->tax_branch_code) ({{ $branch->tax_branch_code }}) @endif</div>@if(ns()->option->get('ns_thai_tax_id'))<div>เลขประจำตัวผู้เสียภาษี {{ ns()->option->get('ns_thai_tax_id') }}</div>@endif</td><td><h1 class="title">{{ $documentTitle }}</h1>
@if($temporary)
<div style="text-align:center;font-weight:bold">TEMPORARY DELIVERY ORDER</div>
@endif<div class="frame" style="margin-top:6px"><strong>เลขที่ {{ $order->code }}</strong><br>วันที่ {{ \Carbon\Carbon::parse($order->created_at)->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}<br>สถานะ {{ $order->paymentStatus }}@if($isDue && $order->final_payment_date)<br>กำหนดชำระ {{ \Carbon\Carbon::parse($order->final_payment_date)->format('d/m/Y') }}@endif</div></td></tr></table>
@if(in_array($order->payment_status,['void','refunded','hold']))<div class="warning">{{ $order->paymentStatus }} — กรุณาตรวจสถานะเอกสารก่อนใช้งาน</div>@endif
<div class="frame" style="margin-bottom:8px"><strong>{{ $buyerName ?: 'ลูกค้าขาจร' }}</strong> · รหัสลูกค้า {{ $order->customer_id }} @if($phone) · โทร. {{ $phone }} @endif
@if($address($order->billing_address))<div>{{ $address($order->billing_address) }}</div>@endif
@if($address($order->shipping_address))<div style="margin-top:4px">จัดส่ง: {{ $address($order->shipping_address) }}</div>@endif</div>
<table class="items"><colgroup><col style="width:7%"><col style="width:37%"><col style="width:8%"><col style="width:15%"><col style="width:15%"><col style="width:18%"></colgroup><thead><tr><th>ลำดับ</th><th>รายการสินค้า</th><th>ภาษี</th><th>จำนวน / หน่วย</th><th>ราคา / หน่วย</th><th>จำนวนเงิน</th></tr></thead><tbody>
@foreach($order->products as $line)<tr><td class="right">{{ $loop->iteration }}</td><td>{{ $line->name }}@if($line->discount > 0)<div class="small">ส่วนลดรายการ {{ $money($line->discount) }} บาท</div>@endif</td><td style="text-align:center">{{ $line->tax_value > 0 ? 'V' : '—' }}</td><td class="right">{{ $quantity($line->quantity) }} {{ $line->unit_name ?: data_get($line->unit,'name','') }}</td><td class="right">{{ $money($line->unit_price) }}</td><td class="right">{{ $money($line->total_price) }}</td></tr>@endforeach
@for($i=$order->products->count();$i<12;$i++)<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>@endfor
</tbody></table>
<div class="closing"><table><tr><td class="frame" style="width:57%"><p class="small">V = รายการมีภาษีตามบิล · — = ไม่มีภาษีที่บันทึกในรายการ</p><p style="white-space:pre-line">{{ $order->note }}</p><p style="margin-top:15px;font-weight:bold">({{ app(\App\Services\ThaiBahtTextService::class)->convert((float)$order->total) }})</p><p class="small">เอกสารนี้ไม่ใช่ใบกำกับภาษีแบบเต็มรูป</p>@if($temporary)<p class="small">เอกสารชั่วคราว ไม่ใช่หลักฐานรับชำระเงิน</p>@endif</td><td style="padding:0"><table class="items"><tbody>
<tr><td>ยอดรวมสินค้า</td><td class="right">{{ $money($order->subtotal) }}</td></tr>
@if($order->discount > 0)<tr><td>ส่วนลดท้ายบิล</td><td class="right">{{ $money($order->discount) }}</td></tr>@endif
@if($order->total_coupons > 0)<tr><td>ส่วนลดคูปอง</td><td class="right">{{ $money($order->total_coupons) }}</td></tr>@endif
@if($order->shipping > 0)<tr><td>ค่าจัดส่ง</td><td class="right">{{ $money($order->shipping) }}</td></tr>@endif
<tr><td>ภาษี{{ $order->tax_type==='inclusive' ? ' (รวมในราคา)' : 'ตามบิล' }}</td><td class="right">{{ $money($order->tax_value) }}</td></tr>
<tr><th>จำนวนเงินรวมสุทธิ</th><th class="right">{{ $money($order->total) }}</th></tr>
<tr><td>รับชำระ</td><td class="right">{{ $money($order->tendered) }}</td></tr>
@if($isDue)<tr><td>ค้างชำระ</td><td class="right">{{ $money($due) }}</td></tr>@else<tr><td>เงินทอน</td><td class="right">{{ $money(max(0,(float)$order->change)) }}</td></tr>@endif
</tbody></table></td></tr></table>
<table><tr><td class="signature">ลงชื่อ ____________________<br>ผู้รับสินค้า<br>วันที่ ____________________</td><td class="signature">ลงชื่อ ____________________<br>ผู้ส่งสินค้า<br>วันที่ ____________________</td><td class="signature">ลงชื่อ ____________________<br>{{ $temporary ? 'ผู้อนุมัติจ่ายสินค้า' : ($paid ? 'ผู้รับเงิน' : 'ผู้จัดทำเอกสาร') }}<br>วันที่ ____________________</td></tr></table></div>
<p class="small" style="margin-top:6px">ผู้จัดทำ {{ $order->user?->username }} · อ้างอิงบิล {{ $order->code }} · จำนวน {{ $order->products->count() }} รายการ</p>
</article>
