<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\PerformanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerformanceController extends Controller
{
    public const PAGES = ['metrics' => 'ตัวชี้วัด KPI', 'goals' => 'เป้าหมายพนักงาน', 'evaluations' => 'ประเมินผลงาน', 'scores' => 'คะแนนและสรุปผล', 'annual' => 'ประเมินประจำปี'];

    public function index(Request $r, string $page)
    {
        abort_unless(isset(self::PAGES[$page]), 404);
        ns()->restrict('read.dashboard');
        ns()->restrict('nexopos.read.performance');
        $r->validate(['branch_id' => 'nullable|integer', 'year' => 'nullable|integer|min:2020|max:2100']);
        $branches = Branch::active()->when(! ns()->hasRole('admin'), fn ($q) => $q->whereKey(auth()->user()->branch_id ?: -1))->get();
        $branch = (int) ($r->input('branch_id') ?: session('selected_branch_id') ?: $branches->first()?->id);
        $year = (int) $r->input('year', now('Asia/Bangkok')->year);
        $s = app(PerformanceService::class);
        $s->authorize($branch);
        $rows = $s->rows($branch, $year);

        return view('pages.dashboard.performance.'.$page, compact('page', 'branch', 'branches', 'year', 'rows') + ['title' => self::PAGES[$page], 'pages' => self::PAGES, 'metrics' => DB::table('nexopos_performance_metrics')->orderBy('name')->get(), 'employees' => $s->employees($branch)->get(), 'summaries' => $rows->groupBy('employee_id')->map(fn ($g) => $s->summarize($g) + ['name' => $g->first()->employee_name]), 'reviews' => DB::table('nexopos_performance_reviews')->where(['branch_id' => $branch, 'year' => $year])->get()]);
    }

    public function store(Request $r, string $action)
    {
        $s = app(PerformanceService::class);
        match ($action) {
            'metric' => $s->metric($r->all()),'goal' => $s->goal($r->all()),'evaluate' => $s->evaluate((int) $r->input('goal_id'), $r->all()),'cancel' => $s->cancel((int) $r->input('goal_id'), $r->all()),'annual' => $s->annual($r->all()),default => abort(404)
        };

        return back()->with('success', 'บันทึกเรียบร้อยแล้ว');
    }
}
