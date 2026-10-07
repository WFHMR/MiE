@extends('pages.dashboard.performance.layout')
@section('performance.body')
<p>ยืนยันแล้วจะเก็บคะแนนและหลักฐานถาวร กรุณาตรวจค่าผลงานจริงก่อนบันทึก</p>
@can('nexopos.manage.performance')
@forelse($rows->where('status','open') as $g)@php($metric=json_decode($g->metric,true))
<section class="card"><h2>{{ $g->employee_name }} · {{ $g->period }} · {{ $metric['name'] }}</h2><p>เป้าหมาย {{ $g->target }} {{ $metric['unit'] }} · น้ำหนัก {{ $g->weight }} · {{ $metric['direction']==='higher'?'ยิ่งมากยิ่งดี':'ยิ่งน้อยยิ่งดี' }}</p><p>{{ $metric['description'] }} {{ $g->note }}</p>
<form method="post" action="{{ route('ns.performance.store',['action'=>'evaluate']) }}">@csrf<input type="hidden" name="goal_id" value="{{ $g->id }}"><label>ผลจริง ({{ $metric['unit'] }})<input type="number" name="actual" step="0.0001" min="0" max="999999999999" required></label><label>หลักฐาน / เลขอ้างอิงรายงาน<textarea name="evidence" maxlength="3000" required></textarea></label><label>ความเห็นผู้ประเมิน<textarea name="comment" maxlength="3000" required></textarea></label><p><label><input type="checkbox" name="confirm" value="1" required>ตรวจสอบแล้วและยืนยันบันทึกผลถาวร</label></p><button class="primary">ยืนยันการประเมิน</button></form>
<details class="no-print"><summary>ยกเลิกเป้าหมายที่ไม่ใช้</summary><form method="post" action="{{ route('ns.performance.store',['action'=>'cancel']) }}">@csrf<input type="hidden" name="goal_id" value="{{ $g->id }}"><label>เหตุผล<input name="reason" minlength="5" maxlength="2000" required></label><button>ยกเลิกเป้าหมาย</button></form></details></section>
@empty<p class="card">ไม่มีเป้าหมายรอประเมินในปีนี้</p>@endforelse
@endcan

<section class="card"><h2>ประวัติการประเมิน</h2>@include('pages.dashboard.performance.rows',['rows'=>$rows->where('status','!=','open')])</section>
@endsection
