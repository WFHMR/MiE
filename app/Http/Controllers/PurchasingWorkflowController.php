<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\Provider;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseReturn;
use App\Models\SupplierQuotation;
use App\Models\TaxGroup;
use App\Models\TransactionAccount;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\PurchasingWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchasingWorkflowController extends Controller
{
    public function __construct(private PurchasingWorkflowService $service) {}

    private function scope(Request $request): array
    {
        ns()->restrict('nexopos.read.procurements');
        $request->validate(['branch_id' => 'nullable|integer|min:1']);
        $branches = app(PurchaseOrderService::class)->branches()->get();
        $branch = (int) ($request->input('branch_id') ?: (ns()->hasRole('admin') ? session('selected_branch_id') : auth()->user()->branch_id) ?: $branches->first()?->id);
        $this->service->authorize($branch);

        return compact('branches', 'branch');
    }

    public function index(Request $request): View
    {
        $scope = $this->scope($request);

        return view('pages.dashboard.purchasing.requests', $scope + ['title' => 'ใบขอซื้อ / เทียบราคาผู้ขาย', 'description' => 'ขอซื้อ → อนุมัติ → เปรียบเทียบราคา → ใบสั่งซื้อ', 'requests' => PurchaseRequest::where('branch_id', $scope['branch'])->latest('id')->paginate(20)->withQueryString()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $r = $this->service->request($request->all());

        return redirect()->route('ns.purchasing.request', $r->id)->with('success', 'ส่งใบขอซื้อให้ผู้มีสิทธิ์แก้ไขจัดซื้อพิจารณาแล้ว');
    }

    public function show(int $id): View
    {
        $r = PurchaseRequest::findOrFail($id);
        $this->service->authorize((int) $r->branch_id);

        return view('pages.dashboard.purchasing.request', ['title' => 'ใบขอซื้อ PR-'.$r->id, 'description' => 'รายละเอียดและใบเสนอราคาจากผู้ขาย', 'document' => $r, 'branchName' => Branch::find($r->branch_id)?->name, 'authorName' => User::find($r->author_id)?->username, 'approverName' => User::find($r->approved_by)?->username, 'quotes' => SupplierQuotation::where('purchase_request_id', $id)->orderBy('total')->get(), 'providers' => Provider::orderBy('first_name')->get()->keyBy('id'), 'taxGroups' => TaxGroup::all()]);
    }

    public function decide(Request $request, int $id): RedirectResponse
    {
        $this->service->decide($id, $request->all());

        return back()->with('success', 'บันทึกผลพิจารณาแล้ว');
    }

    public function quote(Request $request, int $id): RedirectResponse
    {
        $this->service->quote($id, $request->all());

        return back()->with('success', 'บันทึกใบเสนอราคาแล้ว เปรียบเทียบยอดรวมและเงื่อนไขก่อนเลือกผู้ขาย');
    }

    public function selectQuote(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['quote_id' => 'required|integer', 'acknowledge' => 'accepted']);
        $po = $this->service->selectQuote($id, (int) $data['quote_id']);

        return redirect()->route('ns.purchase-orders.show', $po->id)->with('success', 'สร้างใบสั่งซื้อจากราคาที่เลือกแล้ว ยังไม่เพิ่มสต๊อก');
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => 'required|integer', 'search' => 'required|string|max:150']);
        $this->service->authorize((int) $data['branch_id'], 'nexopos.create.procurements');
        $term = trim($data['search']);
        $products = Product::where('stock_management', 'enabled')->where('type', '!=', 'grouped')->where(function ($q) use ($term): void {
            $q->where('name', 'like', '%'.$term.'%')->orWhere('sku', 'like', '%'.$term.'%')->orWhere('barcode', $term)
                ->orWhereHas('unit_quantities', fn ($u) => $u->where('barcode', $term));
        })->orderBy('name')->limit(30)->get(['id', 'name', 'sku', 'unit_group']);

        return response()->json($products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'units' => Unit::where('group_id', $p->unit_group)->get(['id', 'name'])]));
    }

    public function returns(Request $request): View
    {
        $scope = $this->scope($request);
        $request->validate(['search' => 'nullable|string|max:150', 'receipt_id' => 'nullable|integer']);
        $receipt = $request->filled('receipt_id') ? Procurement::findOrFail($request->integer('receipt_id')) : null;
        if ($receipt) {
            $this->service->authorize((int) $receipt->branch_id);
            abort_unless((int) $receipt->branch_id === $scope['branch'], 403);
        }
        $term = trim((string) $request->input('search'));
        $receipts = Procurement::with('provider')->where('branch_id', $scope['branch'])->whereNull('trial_archived_at')->where('delivery_status', 'stocked')
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('invoice_reference', 'like', '%'.$term.'%')->orWhere('id', ctype_digit($term) ? (int) $term : -1)))->latest('id')->paginate(15, ['*'], 'receipts_page')->withQueryString();

        return view('pages.dashboard.purchasing.returns', $scope + ['title' => 'คืนสินค้าให้ซัพพลายเออร์', 'description' => 'ส่งคืนสินค้า → ยืนยันลดหนี้ / รับเงินคืน / รับสินค้าทดแทน', 'receipt' => $receipt, 'receipts' => $receipts, 'returns' => PurchaseReturn::where('branch_id', $scope['branch'])->latest('id')->paginate(15)->withQueryString()]);
    }

    public function storeReturn(Request $request): RedirectResponse
    {
        $r = $this->service->createReturn($request->all());

        return redirect()->route('ns.purchasing.return', $r->id)->with('success', 'สร้างใบคืนฉบับร่างแล้ว ยังไม่ตัดสต๊อก');
    }

    public function showReturn(int $id): View
    {
        $r = PurchaseReturn::findOrFail($id);
        $this->service->authorize((int) $r->branch_id);

        return view('pages.dashboard.purchasing.return', ['title' => 'ใบคืนสินค้า RT-'.$r->id, 'description' => 'ตรวจรายการก่อนยืนยันส่งคืน', 'document' => $r, 'branchName' => Branch::find($r->branch_id)?->name, 'provider' => Provider::find($r->provider_id), 'accounts' => TransactionAccount::orderBy('name')->get()]);
    }

    public function actionReturn(Request $request, int $id): RedirectResponse
    {
        $request->validate(['action' => 'required|in:confirm,cancel,settle', 'acknowledge' => 'accepted']);
        match ($request->input('action')) {
            'confirm' => $this->service->confirmReturn($id),
            'settle' => $this->service->settleReturn($id, $request->all()),
            'cancel' => $this->service->cancelReturn($id),
        };

        return back()->with('success', 'บันทึกสถานะใบคืนสินค้าแล้ว');
    }

    public function report(Request $request): View|StreamedResponse
    {
        $scope = $this->scope($request);
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'format' => 'nullable|in:html,csv']);
        $from = $data['from'] ?? now('Asia/Bangkok')->startOfMonth()->toDateString();
        $to = $data['to'] ?? now('Asia/Bangkok')->toDateString();
        abort_if($to < $from, 422, 'วันสิ้นสุดต้องไม่น้อยกว่าวันเริ่ม');
        $receipts = Procurement::with(['provider', 'products'])->where('branch_id', $scope['branch'])->whereNull('trial_archived_at')->where('delivery_status', 'stocked')->whereDate('delivery_time', '>=', $from)->whereDate('delivery_time', '<=', $to)->orderBy('id')->get();
        $orders = PurchaseOrder::where('branch_id', $scope['branch'])->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->get();
        $returns = PurchaseReturn::where('branch_id', $scope['branch'])->whereIn('status', ['confirmed', 'settled'])->whereDate('confirmed_at', '>=', $from)->whereDate('confirmed_at', '<=', $to)->get();
        $vendors = $receipts->groupBy('provider_id')->map(fn ($rows) => ['name' => trim(($rows->first()->provider?->first_name ?? '').' '.($rows->first()->provider?->last_name ?? '')), 'count' => $rows->count(), 'total' => $rows->sum('cost')])->sortByDesc('total');
        $items = $receipts->flatMap(fn ($r) => $r->products)->groupBy(fn ($p) => $p->product_id.':'.$p->unit_id)->map(fn ($rows) => ['name' => $rows->first()->name, 'unit' => Unit::find($rows->first()->unit_id)?->name, 'frequency' => $rows->pluck('procurement_id')->unique()->count(), 'quantity' => $rows->sum('quantity'), 'total' => $rows->sum('total_purchase_price')])->sortByDesc('frequency');
        if (($data['format'] ?? '') === 'csv') {
            return response()->streamDownload(function () use ($receipts, $vendors, $items, $orders, $returns, $from, $to, $scope): void {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                $row = function (array $cells) use ($out): void {
                    fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\-\t\r]/u', $v) ? "'".$v : $v, $cells), ',', '"', '');
                };
                $row(['รายงานจัดซื้อ', 'สาขา '.$scope['branch'], $from, $to]);
                $row(['เอกสารรับเข้า', 'ชื่อเอกสาร', 'วันที่รับเข้า', 'ผู้ขาย', 'ยอดรับเข้า', 'ลดหนี้สะสม', 'ยอดหลังลดหนี้']);
                foreach ($receipts as $r) {
                    $row([$r->id, $r->name, (string) $r->delivery_time, $r->provider?->first_name, $r->cost, $r->purchase_return_credit, $r->payable_total]);
                }
                $row(['ผู้ขายอันดับต้น', 'จำนวนเอกสาร', 'ยอดรับเข้า']);
                foreach ($vendors as $v) {
                    $row(array_values($v));
                }
                $row(['สินค้าซื้อบ่อย', 'หน่วย', 'จำนวนครั้ง', 'จำนวนรับ', 'มูลค่า']);
                foreach ($items as $i) {
                    $row(array_values($i));
                }
                $row(['ใบสั่งซื้อ', 'ชื่อ', 'สถานะ', 'ยอดสั่ง']);
                foreach ($orders as $p) {
                    $row(['PO-'.$p->id, $p->name, $p->status, collect($p->lines)->sum('total_purchase_price')]);
                }
                $row(['ใบคืน', 'วิธี', 'สถานะ', 'มูลค่าส่งคืน']);
                foreach ($returns as $r) {
                    $row(['RT-'.$r->id, $r->mode, $r->status, $r->total]);
                }
                fclose($out);
            }, 'purchasing-'.$from.'-'.$to.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('pages.dashboard.purchasing.report', $scope + compact('receipts', 'orders', 'returns', 'vendors', 'items', 'from', 'to') + ['title' => 'รายงานจัดซื้อ', 'description' => 'ยอดรับเข้า ผู้ขาย สินค้าซื้อบ่อย ใบสั่งซื้อและการคืนสินค้า']);
    }
}
