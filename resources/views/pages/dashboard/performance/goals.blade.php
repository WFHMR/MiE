@extends('pages.dashboard.performance.layout')
@section('performance.body')
@can('nexopos.manage.performance')
<form class="card" method="post" action="{{ route('ns.performance.store',['action'=>'goal']) }}">@csrf<input type="hidden" name="branch_id" value="{{ $branch }}"><input type="hidden" name="request_key" value="{{ old('request_key', (string) Illuminate\Support\Str::uuid()) }}"><h2>ตั้งเป้าหมายรายเดือน</h2><div class="grid"><label>พนักงาน<select name="employee_id" required><option value="">เลือกพนักงาน</option>@foreach($employees as $u)<option value="{{ $u->id }}">{{ $u->username }}</option>@endforeach</select></label><label>ตัวชี้วัด<select name="metric_id" required><option value="">เลือกตัวชี้วัด</option>@foreach($metrics as $m)<option value="{{ $m->id }}">{{ $m->name }} ({{ $m->unit }})</option>@endforeach</select></label><label>เดือน<input type="month" name="period" required value="{{ old('period',$year.'-'.now()->format('m')) }}"></label><label>เป้าหมาย<input type="number" name="target" required min="0.0001" step="0.0001" max="999999999999"></label><label>น้ำหนักความสำคัญ<input type="number" name="weight" value="1" min="0.01" max="100" step="0.01" required></label></div><label>ข้อตกลง/หมายเหตุ<textarea name="note" maxlength="2000"></textarea></label><button class="primary">บันทึกเป้าหมาย</button></form>
@endcan

<section class="card"><h2>เป้าหมายปี {{ $year }}</h2>@include('pages.dashboard.performance.rows')</section>
@endsection
