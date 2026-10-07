@extends('layout.dashboard')
@section('layout.dashboard.body')
<div class="flex-auto flex flex-col">
    @include(Hook::filter('ns-dashboard-header-file', '../common/dashboard-header'))
    <div class="p-4" id="daily-reconciliation">
        <h1 class="text-2xl font-bold">กระทบยอดบิลรายวัน</h1>
        <form method="get" action="{{ route('ns.dashboard.reports.daily-reconciliation') }}" class="my-4 flex flex-wrap gap-2">
            <label>วันที่ขาย <input type="date" name="date" value="{{ $date }}" required class="border p-2"></label>
            <button class="border rounded px-4 p-2" style="background:#3f8f91;color:white" type="submit">ตรวจยอด</button>
            <button class="border rounded px-4 p-2" type="button" onclick="window.print()">พิมพ์</button>
        </form>
        <p>แสดงทุกบิลของสาขาที่เลือกตามวันที่ขาย รวมบิลพัก ยกเลิก และคืนสินค้า ใช้ข้อมูลรับเงินและคืนเงินล่าสุดของบิลนั้น ไม่ใช่ยอดเงินสดเฉพาะวัน</p>
        <p class="my-2">จำนวน {{ count($report['rows']) }} บิล · ยอดขายคงเหลือ {{ number_format($report['sales'], 2) }} บาท · ค้างชำระ {{ number_format($report['outstanding'], 2) }} บาท</p>
        <p>ยอดรับก่อนหักเงินทอนต้องไม่เทียบตรงกับยอดขาย ช่องเงินทอนเดิมอาจถูกคำนวณใหม่เมื่อคืนสินค้า จึงต้องตรวจหลักฐานหากมีข้อความแจ้งเตือน</p>
        <div class="overflow-x-auto my-4">
        <table class="ns-table w-full border">
            <thead><tr>@foreach(['เลขบิล','สถานะ','ยอดบิลคงเหลือ','รับเงินก่อนทอน','ช่องเงินทอนเดิม','คืนเงิน','ค้างชำระ','สิ่งที่ต้องตรวจ / รายละเอียด'] as $label)<th class="border p-2">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($report['rows'] as $row)
                @php($order = $row['order'])
                <tr>
                    <td class="border p-2">{{ $order->code }}</td>
                    <td class="border p-2">{{ ['paid'=>'ชำระครบ','partially_paid'=>'ชำระบางส่วน','unpaid'=>'ยังไม่ชำระ','partially_refunded'=>'คืนบางส่วน','refunded'=>'คืนทั้งหมด','order_void'=>'ยกเลิก','hold'=>'พักบิล','due'=>'เกินกำหนด','partially_due'=>'ค้างเกินกำหนด'][$order->payment_status] ?? $order->payment_status }}</td>
                    @foreach([$row['total'],$row['payments'],max(0,(float)$order->change),$row['refunds'],$row['due']] as $amount)<td class="border p-2 text-right">{{ number_format($amount, 2) }}</td>@endforeach
                    <td class="border p-2">
                        @foreach($row['flags'] as $flag)<p>{{ $flag }}</p>@endforeach
                        <details><summary>ดูรายการรับ / คืนเงิน</summary>
                            @foreach($order->payments as $payment)<p>รับ {{ number_format($payment->value,2) }} · {{ $payment->identifier }} · {{ $payment->created_at }} · {{ $payment->reference }}</p>@endforeach
                            @foreach($order->refunds as $refund)<p>คืน {{ number_format($refund->total,2) }} · {{ $refund->payment_method }} · {{ $refund->created_at }}</p>@endforeach
                            @if($order->voidance_reason)<p>เหตุผลยกเลิก: {{ $order->voidance_reason }}</p>@endif
                        </details>
                    </td>
                </tr>
            @empty<tr><td colspan="8" class="p-4">ไม่พบบิลในวันที่และสาขานี้</td></tr>@endforelse
            </tbody>
        </table>
        </div>
        <h2 class="font-bold">เลขบิลที่ข้ามช่วง (ตรวจเพิ่ม)</h2>
        <p>ตรวจเลขแบบวันที่-ลำดับจากทุกสาขา เพื่อไม่แจ้งผิดว่าเลขที่ใช้อีกสาขาหาย แสดงไม่เกิน 100 เลขระหว่างเลขแรกและเลขสุดท้ายที่พบ การข้ามเลขไม่ใช่หลักฐานว่าบิลถูกลบ และตรวจจับเลขก่อนแรกหรือหลังสุดไม่ได้</p>
        <p>{{ count($report['gaps']) ? implode(', ', $report['gaps']) : 'ไม่พบเลขข้ามช่วงที่ตรวจได้' }}</p>
        <h2 class="font-bold">ประวัติการเปลี่ยนแปลงบิล</h2>
        <p>เริ่มเก็บตั้งแต่ติดตั้งฟังก์ชันนี้ แสดงล่าสุดไม่เกิน 1,000 รายการ ประวัติก่อนหน้านี้ไม่สามารถสร้างย้อนหลังได้จากข้อมูลที่ไม่มี</p>
        <div class="overflow-x-auto"><table class="ns-table w-full">
            <thead><tr><th>เวลา</th><th>บิล</th><th>ผู้ทำรายการ (รหัส)</th><th>การเปลี่ยนแปลง</th></tr></thead>
            <tbody>@forelse($report['audits'] as $audit)<tr>
                <td class="border p-2">{{ $audit->created_at }}</td><td class="border p-2">{{ $audit->order_code }}</td><td class="border p-2">{{ $audit->actor_id ?? 'ระบบ' }}</td>
                <td class="border p-2">{{ ['created'=>'สร้างบิล','updated'=>'แก้ไขบิล','deleted'=>'ลบบิลพัก'][$audit->action] ?? $audit->action }}
                <details><summary>ก่อน / หลัง</summary>
                @php($before = json_decode($audit->before_data, true) ?? [])
                @php($after = json_decode($audit->after_data, true) ?? [])
                @foreach(['code'=>'เลขบิล','branch_id'=>'รหัสสาขา','payment_status'=>'สถานะ','total'=>'ยอดบิล','tendered'=>'ยอดรับ','change'=>'เงินทอน','voidance_reason'=>'เหตุผลยกเลิก'] as $field => $label)
                    @if(($before[$field] ?? null) !== ($after[$field] ?? null))
                        <p>{{ $label }}: {{ $before[$field] ?? '—' }} → {{ $after[$field] ?? '—' }}</p>
                    @endif
                @endforeach
                </details></td>
            </tr>@empty<tr><td colspan="4">ยังไม่มีประวัติที่บันทึกหลังเปิดใช้ฟังก์ชัน</td></tr>@endforelse</tbody>
        </table></div>
        <p class="my-4">วิธีตรวจ: เลือกสาขาและวันที่ → ตรวจบิลที่มีข้อความแจ้งเตือน → เปิดรายละเอียดรับ/คืนเงินเทียบหลักฐาน ห้ามแก้เป็นชำระครบเพื่อให้ยอดตรงถ้ายังไม่ได้รับเงินจริง หากมีใบเสร็จแต่หาเลขบิลไม่พบ ให้ตรวจประวัติและแจ้งผู้ดูแล ไม่สร้างบิลซ้ำทันที</p>
    </div>
</div>
@endsection
