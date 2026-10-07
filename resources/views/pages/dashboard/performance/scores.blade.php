@extends('pages.dashboard.performance.layout')
@section('performance.body')
<section class="card"><h2>คะแนนถ่วงน้ำหนัก ปี {{ $year }}</h2><p>เป็นผลระหว่างปีเมื่อยังมีรายการค้าง ไม่ใช่ผลประเมินรายปีที่ปิดแล้ว</p><div class="table-wrap"><table><thead><tr><th>พนักงาน</th><th>ประเมินแล้ว</th><th>รอประเมิน</th><th>คะแนน / 100</th></tr></thead><tbody>@forelse($summaries as $s)<tr><td>{{ $s['name'] }}</td><td>{{ $s['count'] }}</td><td>{{ $s['pending'] }}</td><td>{{ $s['score']===null?'ยังไม่มีผล':number_format($s['score'],2) }}</td></tr>@empty<tr><td colspan="4">ยังไม่มีข้อมูล</td></tr>@endforelse</tbody></table></div></section><section class="card"><h2>รายละเอียดที่มาคะแนน</h2>@include('pages.dashboard.performance.rows')</section>
@endsection
