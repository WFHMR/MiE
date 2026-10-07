<table style="width:100%;border-collapse:collapse;font-size:13px">
<thead><tr>@foreach(['ลำดับ','ใบกำกับภาษี / วันที่','ใบส่งสินค้า','ใบวางบิล / วันที่','ครบกำหนด','ชำระเงิน','ก่อนภาษี','ภาษี','รวมเงิน (บาท)','ลดหนี้สะสม','ยอดหลังลดหนี้'] as $heading)<th style="border:1px solid #777;padding:8px;background:#eee;color:#000">{{ $heading }}</th>@endforeach</tr></thead>
<tbody>@forelse($documents as $document)<tr>
<td style="border:1px solid #777;padding:7px">{{ $loop->iteration }}</td>
<td style="border:1px solid #777;padding:7px;overflow-wrap:anywhere">{{ $document->invoice_reference ?: '—' }}<br>{{ substr((string)$document->invoice_date,0,10) }}<br><small>รับเข้า #{{ $document->id }}</small></td>
<td style="border:1px solid #777;padding:7px;overflow-wrap:anywhere">{{ $document->delivery_note_number ?: '—' }}</td>
<td style="border:1px solid #777;padding:7px;overflow-wrap:anywhere">{{ $document->billing_note_number ?: '—' }}<br>{{ substr((string)$document->billing_note_date,0,10) }}</td>
<td style="border:1px solid #777;padding:7px">{{ substr((string)$document->due_date,0,10) ?: '—' }}</td>
<td style="border:1px solid #777;padding:7px">{{ $document->payment_status==='paid'?'ชำระแล้ว':'ยังไม่ชำระ' }}</td>
@foreach([(float)$document->cost-(float)$document->tax_value,$document->tax_value,$document->cost,$document->purchase_return_credit,$document->payable_total] as $amount)<td style="border:1px solid #777;padding:7px;text-align:right;white-space:nowrap">{{ number_format($amount,2) }}</td>@endforeach
</tr>@empty<tr><td colspan="11" style="padding:20px;text-align:center">ไม่พบเอกสารตามเงื่อนไขที่เลือก</td></tr>@endforelse</tbody>
<tbody><tr><th colspan="6" style="border-top:2px solid;padding:10px;text-align:right">รวม {{ $documents->count() }} เอกสาร</th>@foreach([$documents->sum('cost')-$documents->sum('tax_value'),$documents->sum('tax_value'),$documents->sum('cost'),$documents->sum('purchase_return_credit'),$documents->sum('payable_total')] as $sum)<th style="border-top:2px solid;padding:10px;text-align:right;white-space:nowrap">{{ number_format($sum,2) }}</th>@endforeach</tr></tbody>
</table>
