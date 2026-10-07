<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>สรุปวางบิลซัพพลายเออร์</title>
<style>body{font-family:Tahoma,Arial,sans-serif;color:#000;background:#fff;margin:24px;font-size:14px}h1{font-size:21px;text-align:center}header{display:flex;justify-content:space-between;gap:20px;margin-bottom:20px}thead{display:table-header-group}tr{break-inside:avoid}.signatures{display:flex;justify-content:space-between;margin-top:60px}@page{size:A4 landscape;margin:12mm}@media print{.controls{display:none}body{margin:0}}@media screen{main{max-width:1100px;margin:auto}.table-wrap{overflow:auto}}</style></head><body><main>
<div class="controls"><button onclick="window.print()">พิมพ์ / บันทึก PDF</button></div>
<header><img src="{{ ns_logo('rectangle') }}" alt="โลโก้" style="max-width:80px;max-height:80px;object-fit:contain"><div>{{ ns()->option->get('ns_store_name') }}<br>{{ ns()->option->get('ns_store_address') }}</div></header>
<h1>สรุปวางบิลซัพพลายเออร์</h1>
<p>ซัพพลายเออร์: {{ $provider?->code }} {{ $provider?->first_name }} {{ $provider?->last_name }}<br>สาขา: {{ $branchName }}<br>{{ $dateLabels[$dateField] }}: {{ $data['from']??'—' }} ถึง {{ $data['to']??'—' }}<br>สถานะชำระเงิน: {{ ['unpaid'=>'ยังไม่ชำระ','paid'=>'ชำระแล้ว','all'=>'ทั้งหมด'][$data['payment']??'unpaid'] }}</p>
<div class="table-wrap">@include('pages.dashboard.procurements.supplier-billing-table')</div>
<p>สรุปจากเอกสารรับเข้าที่ผ่านการยืนยัน ไม่รวมรายการทดลอง · พิมพ์เมื่อ {{ now()->format('d/m/Y H:i') }}</p>
<div class="signatures"><span>ผู้จัดทำ __________________</span><span>ผู้ตรวจสอบ __________________</span><span>ผู้รับวางบิล __________________</span></div>
</main></body></html>
