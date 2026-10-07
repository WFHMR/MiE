<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\BusinessDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessDashboardController extends Controller
{
    private const PERMISSIONS = ['sales' => 'nexopos.reports.sales', 'purchase' => 'nexopos.read.procurements', 'inventory' => 'nexopos.read.products', 'financial' => 'nexopos.reports.transactions', 'kpi' => 'nexopos.reports.sales'];

    private const TITLES = ['sales' => 'แดชบอร์ดยอดขาย', 'purchase' => 'แดชบอร์ดจัดซื้อ', 'inventory' => 'แดชบอร์ดคลังสินค้า', 'financial' => 'แดชบอร์ดการเงิน', 'kpi' => 'แดชบอร์ด KPI'];

    private function scope(Request $request, string $kind): array
    {
        ns()->restrict('read.dashboard');
        ns()->restrict(self::PERMISSIONS[$kind]);
        if (in_array($kind, ['financial', 'kpi'])) {
            foreach (['nexopos.reports.sales', 'nexopos.read.procurements', 'nexopos.reports.transactions'] as $permission) {
                ns()->restrict($permission);
            }
        }
        if ($kind === 'kpi') {
            ns()->restrict('nexopos.read.products');
        }
        $request->validate(['branch_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'month' => 'nullable|date_format:Y-m']);
        $branches = Branch::active()->when(! ns()->hasRole('admin'), fn ($q) => $q->whereKey(auth()->user()->branch_id ?: -1))->get();
        $branch = (int) ($request->input('branch_id') ?: (ns()->hasRole('admin') ? session('selected_branch_id') : auth()->user()->branch_id) ?: $branches->first()?->id);
        abort_unless($branches->contains('id', $branch), 403);
        $from = $request->input('from', now('Asia/Bangkok')->startOfMonth()->toDateString());
        $to = $request->input('to', now('Asia/Bangkok')->toDateString());
        $month = $request->input('month', now('Asia/Bangkok')->format('Y-m'));
        abort_if($to < $from, 422, 'วันที่สิ้นสุดต้องไม่น้อยกว่าวันเริ่ม');

        return compact('branches', 'branch', 'from', 'to', 'month', 'kind') + ['title' => self::TITLES[$kind]];
    }

    private function show(Request $request, string $kind)
    {
        $scope = $this->scope($request, $kind);
        $service = app(BusinessDashboardService::class);
        $data = match ($kind) {
            'inventory' => $service->inventory($scope['branch']),'kpi' => $service->kpi($scope['branch'], $scope['month']),default => $service->$kind($scope['branch'], $scope['from'], $scope['to'])
        };

        return view('pages.dashboard.business.'.$kind, $scope + ['data' => $data, 'metrics' => BusinessDashboardService::METRICS]);
    }

    public function sales(Request $r)
    {
        return $this->show($r, 'sales');
    }

    public function purchase(Request $r)
    {
        return $this->show($r, 'purchase');
    }

    public function inventory(Request $r)
    {
        return $this->show($r, 'inventory');
    }

    public function financial(Request $r)
    {
        return $this->show($r, 'financial');
    }

    public function kpi(Request $r)
    {
        return $this->show($r, 'kpi');
    }

    public function saveTargets(Request $request)
    {
        $scope = $this->scope($request, 'kpi');
        abort_unless(ns()->hasRole('admin'), 403, 'ผู้ดูแลระบบเป็นผู้กำหนดเป้าหมาย');
        $rules = ['month' => 'required|date_format:Y-m', 'targets' => 'required|array:'.implode(',', array_keys(BusinessDashboardService::METRICS))];
        foreach (BusinessDashboardService::METRICS as $key => $metric) {
            $rules['targets.'.$key] = 'nullable|numeric|min:0|max:'.($metric['unit'] === '%' ? 100 : 999999999999).'|decimal:0,2';
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($scope, $data) {
            foreach (BusinessDashboardService::METRICS as $key => $metric) {
                $where = ['branch_id' => $scope['branch'], 'month' => $data['month'].'-01', 'metric' => $key];
                $value = $data['targets'][$key] ?? null;
                if ($value === null || $value === '') {
                    DB::table('nexopos_kpi_targets')->where($where)->delete();
                } else {
                    DB::table('nexopos_kpi_targets')->updateOrInsert($where, ['target' => $value, 'author_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });

        return redirect()->route('ns.business.kpi', ['branch_id' => $scope['branch'], 'month' => $data['month']])->with('success', 'บันทึกเป้าหมายสาขาและเดือนที่เลือกแล้ว');
    }
}
