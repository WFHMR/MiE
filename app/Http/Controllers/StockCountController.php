<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\StockCount;
use App\Services\StockCountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockCountController extends Controller
{
    public function __construct(private StockCountService $counts) {}

    public function index(): View
    {
        ns()->restrict('nexopos.stock-counts.work');

        return view('pages.dashboard.inventory.counts', [
            'title' => 'ตรวจนับสต๊อก', 'description' => 'นับสินค้าแยกสาขาและตรวจสอบก่อนปรับยอดจริง',
            'branches' => $this->counts->branches()->get(),
            'documents' => StockCount::whereIn('branch_id', $this->counts->branches()->select('id'))->latest('id')->paginate(20),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        ns()->restrict('nexopos.stock-counts.work');
        $data = $request->validate(['branch_id' => 'required|integer', 'title' => 'required|string|max:150']);
        $this->counts->authorizeBranch((int) $data['branch_id']);
        $count = new StockCount;
        $count->branch_id = $data['branch_id'];
        $count->title = $data['title'];
        $count->author_id = auth()->id();
        $count->status = 'draft';
        $count->version = 1;
        $count->lines = [];
        $count->save();

        return redirect()->route('ns.dashboard.inventory.counts.show', $count->id);
    }

    public function show(Request $request, int $id): View
    {
        $count = StockCount::findOrFail($id);
        $this->counts->access($count);
        $request->validate(['q' => 'nullable|string|max:100']);
        $query = trim((string) $request->query('q', ''));
        $matches = collect();
        if ($query !== '' && $count->status === 'draft') {
            $matches = ProductUnitQuantity::with(['product', 'unit'])
                ->where('branch_id', $count->branch_id)
                ->whereHas('product', fn ($q) => $q->where('stock_management', Product::STOCK_MANAGEMENT_ENABLED)->where('type', '!=', Product::TYPE_GROUPED))
                ->where(function ($q) use ($query): void {
                    $q->where('barcode', $query)->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$query.'%')->orWhere('sku', $query)->orWhere('barcode', $query));
                })->orderBy('id')->limit(50)->get()->filter(fn ($row) => $row->unit && (! $row->product->stock_on_base_unit || $row->unit->base_unit));
        }

        return view('pages.dashboard.inventory.counts', [
            'title' => 'ตรวจนับสต๊อก #'.$count->id, 'description' => $count->title,
            'count' => $count, 'branch' => $this->counts->branches()->findOrFail($count->branch_id), 'matches' => $matches, 'query' => $query,
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        ns()->restrict('nexopos.stock-counts.work');
        $data = $request->validate([
            'version' => 'required|integer|min:1', 'action' => 'required|in:add,save,remove,confirm',
            'row_id' => 'required_unless:action,confirm|integer|min:1',
            'quantity' => 'required_if:action,save|nullable|numeric|min:0|max:999999999|decimal:0,5',
            'acknowledge' => 'accepted_if:action,confirm',
        ]);
        $this->counts->change($id, (int) $data['version'], function (StockCount $count) use ($data): void {
            if ($data['action'] === 'confirm') {
                $this->counts->confirm($count);

                return;
            }
            $lines = $count->lines;
            $key = (string) $data['row_id'];
            if ($data['action'] === 'add') {
                if (isset($lines[$key])) {
                    throw ValidationException::withMessages(['count' => 'มีสินค้าหน่วยนี้ในใบนับแล้ว กรุณาแก้จำนวนที่รายการเดิม']);
                }
                if (count($lines) >= 200) {
                    throw ValidationException::withMessages(['count' => 'สูงสุด 200 รายการต่อใบนับ กรุณาเปิดใบนับถัดไป']);
                }
                $row = $this->counts->row((int) $key, (int) $count->branch_id);
                $lines[$key] = ['id' => $row->id, 'name' => $row->product->name, 'unit' => $row->unit->name, 'barcode' => $row->barcode,
                    'before' => (float) $row->quantity, 'counted' => null, 'history' => $this->counts->history($row), 'updated_at' => (string) $row->updated_at, 'counted_by' => null, 'counted_at' => null];
            } else {
                abort_unless(isset($lines[$key]), 404);
                if ($data['action'] === 'remove') {
                    unset($lines[$key]);
                } else {
                    $lines[$key]['counted'] = (float) $data['quantity'];
                    $lines[$key]['counted_by'] = auth()->id();
                    $lines[$key]['counted_at'] = now()->toDateTimeString();
                }
            }
            $count->lines = $lines;
        });

        return redirect()->route('ns.dashboard.inventory.counts.show', $id)->with('success', $data['action'] === 'confirm' ? 'ยืนยันและปรับยอดสต๊อกเรียบร้อยแล้ว' : 'บันทึกใบนับแล้ว ยังไม่ปรับสต๊อกจริง');
    }
}
