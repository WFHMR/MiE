<?php

namespace App\Http\Controllers;

use App\Services\PurchaseOrderService;
use App\Services\ReplenishmentReportService;
use Illuminate\Http\Request;

class ReplenishmentReportController extends Controller
{
    public function index(Request $request)
    {
        foreach (['nexopos.read.procurements', 'nexopos.reports.sales', 'nexopos.read.products'] as $permission) {
            ns()->restrict($permission);
        }
        $request->validate(['branch_id' => 'nullable|integer|min:1', 'as_of' => 'nullable|date_format:Y-m-d|before:'.now('Asia/Bangkok')->toDateString(), 'basis' => 'nullable|in:week,1,3,6', 'cover' => 'nullable|integer|min:1|max:365', 'lead' => 'nullable|integer|min:0|max:365', 'buffer' => 'nullable|integer|min:0|max:365', 'rounding' => 'nullable|in:whole,decimal', 'q' => 'nullable|string|max:150', 'export' => 'nullable|in:csv']);
        $branches = app(PurchaseOrderService::class)->branches()->get();
        $branch = (int) ($request->input('branch_id') ?: (ns()->hasRole('admin') ? session('selected_branch_id') : auth()->user()->branch_id) ?: $branches->first()?->id);
        abort_unless($branches->contains('id', $branch), 403);
        $asOf = $request->input('as_of') ?: now('Asia/Bangkok')->subDay()->toDateString();
        $basis = $request->input('basis') ?: '3';
        $cover = (int) ($request->input('cover') ?? 14);
        $lead = (int) ($request->input('lead') ?? 3);
        $buffer = (int) ($request->input('buffer') ?? 0);
        $rounding = $request->input('rounding') ?: 'whole';
        $q = trim($request->input('q') ?? '');
        $report = app(ReplenishmentReportService::class)->build($branch, $asOf, $basis, $cover, $lead, $buffer, $rounding === 'whole');
        $rows = $report['rows']->filter(fn ($r) => $q === '' || mb_stripos($r['name'].' '.$r['sku'], $q) !== false)->values();
        if ($request->input('export') === 'csv') {
            return response()->streamDownload(function () use ($rows, $branches, $branch, $asOf, $basis, $cover, $lead, $buffer, $report) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                $safe = static fn ($v) => is_string($v) && preg_match('/^[\s]*[=+@-]/u', $v) ? "'".$v : $v;
                $write = static fn (array $fields) => fputcsv($out, $fields, ',', '"', '');
                $write(array_map($safe, ['สาขา', $branches->firstWhere('id', $branch)->name, 'ยอดขายถึง', $asOf, 'ฐานเฉลี่ย', $basis, 'วันครอบคลุมรวม', $cover + $lead + $buffer]));
                foreach ($report['periods'] as $key => $period) {
                    $write([$key, $period['from'], $period['to'], $period['days'].' วัน']);
                }
                $write(['จำนวนเติมก่อนหักของรอรับ/ยอดจอง สต๊อกเป็นยอดปัจจุบัน อัตราแปลงหน่วยปัจจุบัน ยอดขายหักคืนตามสถานะล่าสุด']);
                $write(['สินค้า', 'SKU', 'หน่วยสต๊อก', '7 วันล่าสุด', '7 วันก่อน', 'W/W %', '1 เดือน', '3 เดือน', '6 เดือน', 'เฉลี่ย/วัน', 'คงเหลือปัจจุบัน', 'พอขายกี่วัน', 'จำนวนเติมก่อนหักของรอรับ']);
                foreach ($rows as $r) {
                    $write(array_map($safe, [$r['name'], $r['sku'], $r['unit'], $r['totals']['week'], $r['totals']['previous'], $r['wow'] === null ? '' : round($r['wow'], 2), $r['totals'][1], $r['totals'][3], $r['totals'][6], round($r['average'], 5), $r['stock'], $r['coverage'] === null ? '' : round($r['coverage'], 2), $r['suggested'] ?? '']));
                }
                fclose($out);
            }, 'replenishment-'.$branch.'-'.$asOf.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $title = 'วางแผนสั่งซื้อจากยอดขายย้อนหลัง';

        return view('pages.dashboard.reports.replenishment', compact('title', 'branches', 'branch', 'asOf', 'basis', 'cover', 'lead', 'buffer', 'rounding', 'q', 'report', 'rows'));
    }
}
