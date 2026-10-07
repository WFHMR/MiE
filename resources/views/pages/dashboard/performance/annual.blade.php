@extends('pages.dashboard.performance.layout')
@section('performance.body')
<p>สรุปคะแนนระหว่างปีได้ในหน้าคะแนนและสรุปผล หน้านี้ใช้ยืนยันปิดผลหลังสิ้นปีและเก็บแผนพัฒนาพนักงาน</p>
@can('nexopos.manage.performance')@if($year<now('Asia/Bangkok')->year)
<form class="card" method="post" action="{{ route('ns.performance.store',['action'=>'annual']) }}">@csrf<input type="hidden" name="branch_id" value="{{ $branch }}"><input type="hidden" name="year" value="{{ $year }}"><h2>ยืนยันผลประจำปี {{ $year }}</h2><label>พนักงาน<select name="employee_id" required><option value="">เลือกพนักงานที่มีผลประเมิน</option>@foreach($summaries as $id=>$s)@if(!$reviews->contains('employee_id',$id))<option value="{{ $id }}">{{ $s['name'] }} · คะแนน {{ $s['score'] ?? '—' }} · ค้าง {{ $s['pending'] }}</option>
@endif
@endforeach</select></label><label>จุดเด่น<textarea name="strengths" required maxlength="3000"></textarea></label><label>สิ่งที่ควรพัฒนา<textarea name="improvements" required maxlength="3000"></textarea></label><label>แผนพัฒนาปีถัดไป<textarea name="plan" required maxlength="3000"></textarea></label><p><label><input type="checkbox" name="confirm" value="1" required>ตรวจสอบแล้ว ยืนยันปิดปีและเก็บผลถาวร</label></p><button class="primary">ยืนยันผลรายปี</button></form>
@endif

@endcan

@forelse($reviews as $review)<section class="card"><h2>{{ $summaries[$review->employee_id]['name'] ?? 'พนักงาน #'.$review->employee_id }} · ปี {{ $review->year }}</h2><p class="metric">{{ number_format($review->score,2) }} / 100</p><p>จุดเด่น: {{ $review->strengths }}</p><p>สิ่งที่ควรพัฒนา: {{ $review->improvements }}</p><p>แผนพัฒนา: {{ $review->plan }}</p><p>ยืนยันโดยผู้ใช้ #{{ $review->reviewer_id }} เมื่อ {{ $review->created_at }}</p><details><summary>หลักฐานคะแนนที่ใช้ปิดปี</summary>@include('pages.dashboard.performance.rows',['rows'=>collect(json_decode($review->snapshot))])</details></section>@empty<p class="card">ยังไม่มีผลประจำปีที่ยืนยันแล้ว</p>@endforelse
@endsection
