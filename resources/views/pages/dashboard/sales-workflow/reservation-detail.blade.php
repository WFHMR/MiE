<section class="card"><h2>การจองสต๊อก</h2><p>จองครบทั้งใบสั่งขายโดยไม่ลดยอดคงเหลือจริง ยอดพร้อมขาย = คงเหลือ − จองทั้งหมดในสาขา เมื่อออกบิลหรือยืนยันส่งตามขั้นตอนที่เลือก ระบบใช้ยอดจองและตัดสต๊อกครั้งเดียว หากยกเลิกใบสั่งขายจะปลดจองอัตโนมัติ</p><p>การจองไม่มีวันหมดอายุอัตโนมัติ ผู้รับผิดชอบต้องปลดจองเมื่อไม่ต้องการสินค้าแล้ว การพักบิล POS ไม่ถือเป็นการจองสินค้า</p>
<p><a href="{{ route('ns.sales.reservations',['branch_id'=>$document->branch_id]) }}">ดูประวัติการจองในสาขานี้</a></p>
<div class="table-wrap"><table><thead><tr><th>สินค้า / หน่วยสต๊อก</th><th>คงเหลือจริง</th><th>จองทุกใบ</th><th>พร้อมขาย</th></tr></thead><tbody>
@foreach($document->lines as $line)
@php
 $bearing=app(\App\Services\ProductService::class)->resolveStockBearingUnit($line['product_id'],$line['unit_id'],(int)$document->branch_id);
 $stock=\App\Models\ProductUnitQuantity::with('unit')->where('branch_id',$document->branch_id)->where('product_id',$line['product_id'])->where('unit_id',$bearing['unit_id'])->first();
@endphp
<tr><td>{{ $line['name'] }} / {{ $stock?->unit?->name ?? 'หน่วยถูกลบ' }}</td><td>{{ $stock ? number_format($stock->quantity,5) : '—' }}</td><td>{{ $stock ? number_format($stock->reserved_quantity,5) : '—' }}</td><td>{{ $stock ? number_format($stock->quantity-$stock->reserved_quantity,5) : '—' }}</td></tr>
@endforeach</tbody></table></div>
@php $history=\Illuminate\Support\Facades\DB::table('nexopos_stock_reservations')->where('sales_document_id',$document->id)->orderByDesc('id')->get(); @endphp
<p>ยอดจองของใบนี้: {{ $history->where('status','active')->count() ? 'จองอยู่' : 'ไม่มีการจองค้าง' }}</p>
@foreach($history as $entry)<p class="muted">#{{ $entry->id }} · {{ ['active'=>'จองอยู่','released'=>'ปลดจองแล้ว','consumed'=>'ใช้กับบิลขายแล้ว'][$entry->status] ?? $entry->status }} · {{ number_format($entry->quantity,5) }} หน่วยสต๊อก · ผู้จอง #{{ $entry->author_id }} · {{ \Carbon\Carbon::parse($entry->created_at)->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</p>@endforeach
</section>
