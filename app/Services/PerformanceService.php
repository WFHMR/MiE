<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PerformanceService
{
    public function authorize(int $branch, bool $write = false): void
    {
        ns()->restrict('nexopos.'.($write ? 'manage' : 'read').'.performance');
        abort_unless(Branch::active()->whereKey($branch)->exists() && (ns()->hasRole('admin') || (int) auth()->user()->branch_id === $branch), 403);
    }

    public function employees(int $branch)
    {
        return User::where('active', true)->where('branch_id', $branch)->whereHas('roles', fn ($q) => $q->where('namespace', '!=', Role::STORECUSTOMER))->orderBy('username');
    }

    public function metric(array $input): int
    {
        ns()->restrict('nexopos.manage.performance');
        abort_unless(ns()->hasRole('admin'), 403);
        $data = Validator::make($input, ['name' => 'required|string|max:150', 'unit' => 'required|string|max:40', 'direction' => ['required', Rule::in(['higher', 'lower'])], 'description' => 'required|string|max:2000'])->validate();

        return DB::table('nexopos_performance_metrics')->insertGetId($data + ['author_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function goal(array $input): int
    {
        $d = Validator::make($input, ['request_key' => 'required|uuid', 'branch_id' => 'required|integer', 'employee_id' => 'required|integer', 'metric_id' => 'required|integer', 'period' => 'required|date_format:Y-m', 'target' => 'required|numeric|min:0.0001|max:999999999999|decimal:0,4', 'weight' => 'required|numeric|min:0.01|max:100|decimal:0,2', 'note' => 'nullable|string|max:2000'])->validate();
        $this->authorize((int) $d['branch_id'], true);

        return DB::transaction(function () use ($d) {
            // The employee lock serializes new goals with annual finalization.
            $u = $this->employees((int) $d['branch_id'])->whereKey($d['employee_id'])->lockForUpdate()->firstOrFail();
            $old = DB::table('nexopos_performance_goals')->where('request_key', $d['request_key'])->first();
            if ($old) {
                abort_unless($old->branch_id == $d['branch_id'] && $old->employee_id == $u->id, 409);

                return $old->id;
            }
            abort_if(DB::table('nexopos_performance_reviews')->where(['branch_id' => $d['branch_id'], 'employee_id' => $u->id, 'year' => (int) substr($d['period'], 0, 4)])->exists(), 422, 'ปีนี้ปิดการประเมินแล้ว');
            $m = DB::table('nexopos_performance_metrics')->find($d['metric_id']);
            abort_unless($m, 422, 'ไม่พบตัวชี้วัด');
            abort_if(DB::table('nexopos_performance_goals')->where(['branch_id' => $d['branch_id'], 'employee_id' => $u->id, 'metric_id' => $m->id, 'period' => $d['period']])->exists(), 422, 'มีเป้าหมายตัวชี้วัดนี้ในเดือนนี้แล้ว');

            return DB::table('nexopos_performance_goals')->insertGetId($d + ['employee_name' => $u->username, 'metric' => json_encode($m, JSON_UNESCAPED_UNICODE), 'author_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public static function score(float $actual, float $target, string $direction): float
    {
        return round(min(100, max(0, $direction === 'higher' ? $actual / $target * 100 : ($actual <= 0 ? 100 : $target / $actual * 100))), 2);
    }

    public function evaluate(int $id, array $input): void
    {
        $d = Validator::make($input, ['actual' => 'required|numeric|min:0|max:999999999999|decimal:0,4', 'evidence' => 'required|string|max:3000', 'comment' => 'required|string|max:3000', 'confirm' => 'accepted'])->validate();
        DB::transaction(function () use ($id, $d) {
            $g = DB::table('nexopos_performance_goals')->where('id', $id)->lockForUpdate()->first();
            abort_unless($g, 404);
            $this->authorize($g->branch_id, true);
            abort_if((int) $g->employee_id === (int) auth()->id(), 403, 'ไม่อนุญาตให้ยืนยันผลประเมินของตนเอง');
            abort_unless($g->status === 'open', 422, 'เป้าหมายนี้ประเมินหรือยกเลิกแล้ว');
            $metric = json_decode($g->metric, true);
            DB::table('nexopos_performance_evaluations')->insert(['goal_id' => $id, 'actual' => $d['actual'], 'score' => self::score($d['actual'], $g->target, $metric['direction']), 'evidence' => $d['evidence'], 'comment' => $d['comment'], 'reviewer_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('nexopos_performance_goals')->where('id', $id)->update(['status' => 'evaluated', 'updated_at' => now()]);
        });
    }

    public function cancel(int $id, array $input): void
    {
        $d = Validator::make($input, ['reason' => 'required|string|min:5|max:2000'])->validate();
        DB::transaction(function () use ($id, $d) {
            $g = DB::table('nexopos_performance_goals')->where('id', $id)->lockForUpdate()->first();
            abort_unless($g, 404);
            $this->authorize($g->branch_id, true);
            abort_unless($g->status === 'open', 422, 'ยกเลิกได้เฉพาะเป้าหมายที่ยังไม่ประเมิน');
            DB::table('nexopos_performance_goals')->where('id', $id)->update(['status' => 'cancelled', 'cancellation_reason' => $d['reason'], 'updated_at' => now()]);
        });
    }

    public function rows(int $branch, int $year)
    {
        $this->authorize($branch);

        return DB::table('nexopos_performance_goals as g')->leftJoin('nexopos_performance_evaluations as e', 'e.goal_id', '=', 'g.id')->where('g.branch_id', $branch)->whereBetween('g.period', [$year.'-01', $year.'-12'])->select('g.*', 'e.actual', 'e.score', 'e.evidence', 'e.comment', 'e.reviewer_id')->orderByDesc('g.period')->orderBy('g.id')->get();
    }

    public function summarize($rows): array
    {
        $valid = $rows->where('status', 'evaluated');
        $weight = $valid->sum('weight');

        return ['count' => $valid->count(), 'pending' => $rows->where('status', 'open')->count(), 'score' => $weight > 0 ? round($valid->sum(fn ($g) => $g->score * $g->weight) / $weight, 2) : null];
    }

    public function annual(array $input): int
    {
        $d = Validator::make($input, ['branch_id' => 'required|integer', 'employee_id' => 'required|integer', 'year' => 'required|integer|min:2020|max:2100', 'strengths' => 'required|string|max:3000', 'improvements' => 'required|string|max:3000', 'plan' => 'required|string|max:3000', 'confirm' => 'accepted'])->validate();
        $this->authorize((int) $d['branch_id'], true);
        abort_if((int) $d['employee_id'] === (int) auth()->id(), 403, 'ไม่อนุญาตให้ยืนยันผลประเมินของตนเอง');
        abort_if((int) $d['year'] >= now('Asia/Bangkok')->year, 422, 'สรุปรายปีดูได้ตลอด แต่ยืนยันปิดปีได้หลังสิ้นปีเท่านั้น');

        return DB::transaction(function () use ($d) {
            User::whereKey($d['employee_id'])->lockForUpdate()->firstOrFail();
            $key = ['branch_id' => $d['branch_id'], 'employee_id' => $d['employee_id'], 'year' => $d['year']];
            $old = DB::table('nexopos_performance_reviews')->where($key)->first();
            if ($old) {
                return $old->id;
            }
            $rows = $this->rows($d['branch_id'], $d['year'])->where('employee_id', $d['employee_id']);
            $summary = $this->summarize($rows);
            abort_if(! $summary['count'] || $summary['pending'], 422, 'ต้องประเมินหรือยกเลิกเป้าหมายที่ค้างทั้งหมดก่อนปิดปี');

            return DB::table('nexopos_performance_reviews')->insertGetId($key + ['score' => $summary['score'], 'snapshot' => json_encode($rows->values(), JSON_UNESCAPED_UNICODE), 'strengths' => $d['strengths'], 'improvements' => $d['improvements'], 'plan' => $d['plan'], 'reviewer_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
