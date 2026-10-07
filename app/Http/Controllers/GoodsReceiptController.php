<?php

namespace App\Http\Controllers;

use App\Models\Procurement;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\TaxGroup;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoodsReceiptController extends Controller
{
    public function index(Request $request)
    {
        ns()->restrict('nexopos.read.procurements');
        $request->validate(['branch_id' => 'nullable|integer|min:1', 'source' => 'nullable|in:all,po,invoice,no_bill', 'status' => 'nullable|in:all,pending,approved,cancelled', 'q' => 'nullable|string|max:100', 'lookup' => 'nullable|boolean']);
        $branches = app(PurchaseOrderService::class)->branches()->get();
        $branch = (int) ($request->input('branch_id') ?: (ns()->hasRole('admin') ? session('selected_branch_id') : auth()->user()->branch_id) ?: $branches->first()?->id);
        app(PurchaseOrderService::class)->authorize($branch);
        $source = $request->input('source') ?: 'all';
        $status = $request->input('status') ?: 'all';
        $q = trim($request->input('q') ?? '');
        if ($request->boolean('lookup')) {
            ns()->restrict('nexopos.create.procurements');
            if ($q === '') {
                return response()->json([]);
            }
            $found = ProductUnitQuantity::with(['product', 'unit'])->where('branch_id', $branch)->whereHas('product', fn ($query) => $query->where('stock_management', 'enabled')->where('type', '!=', 'grouped'))
                ->where(fn ($query) => $query->where('barcode', $q)->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$q.'%')->orWhere('sku', $q)->orWhere('barcode', $q)))
                ->whereHas('unit')->limit(30)->get();

            return response()->json($found->map(fn ($r) => ['id' => $r->id, 'name' => $r->product->name, 'unit' => $r->unit->name])->values());
        }
        $documents = Procurement::with('provider')->where('branch_id', $branch)->whereNull('trial_archived_at')
            ->when($source === 'po', fn ($r) => $r->whereNotNull('purchase_order_id'))
            ->when(in_array($source, ['invoice', 'no_bill']), fn ($r) => $r->where('receipt_source', $source))
            ->when($status !== 'all', fn ($r) => $r->where('billing_approval_status', $status))
            ->latest('id')->paginate(25)->withQueryString();
        $providers = Provider::orderBy('first_name')->get();
        $taxGroups = TaxGroup::all();
        $title = 'รับเข้าสินค้า / ประวัติใบรับ';

        return view('pages.dashboard.procurements.goods-receipts', compact('title', 'branches', 'branch', 'source', 'status', 'documents', 'providers', 'taxGroups'));
    }

    public function store(Request $request)
    {
        $document = app(GoodsReceiptService::class)->create($request->all());

        return redirect()->route('ns.goods-receipts.show', $document->id)->with('success', 'บันทึกใบรับสินค้าแล้ว กรุณาตรวจเอกสารและยืนยันเพื่อเข้าสต๊อก');
    }

    public function show(int $id)
    {
        ns()->restrict('nexopos.read.procurements');
        $document = Procurement::with(['products', 'provider'])->findOrFail($id);
        app(PurchaseOrderService::class)->authorize((int) $document->branch_id);
        $branch = app(PurchaseOrderService::class)->branches()->findOrFail($document->branch_id);
        $title = 'ใบรับสินค้า GR-'.str_pad((string) $document->id, 8, '0', STR_PAD_LEFT);
        $version = hash('sha256', json_encode($document->getAttributes()));

        return view('pages.dashboard.procurements.goods-receipts', compact('document', 'title', 'branch', 'version'));
    }

    public function confirm(Request $request, int $id)
    {
        ns()->restrict(['nexopos.read.procurements', 'nexopos.update.procurements']);
        $data = $request->validate(['acknowledge' => 'required|accepted', 'version' => 'required|string|size:64']);
        DB::transaction(function () use ($id, $data): void {
            $d = Procurement::lockForUpdate()->findOrFail($id);
            app(PurchaseOrderService::class)->authorize((int) $d->branch_id);
            abort_unless(in_array($d->receipt_source, ['invoice', 'no_bill']), 422, 'กรุณายืนยันใบรับ PO ในหน้าตรวจรับเดิม');
            if ($d->billing_approval_status !== 'approved') {
                abort_unless(hash_equals(hash('sha256', json_encode($d->getAttributes())), $data['version']), 422, 'เอกสารเปลี่ยนแล้ว กรุณาเปิดใหม่และตรวจอีกครั้ง');
            }
            app(PurchaseOrderService::class)->approveBilling($id);
        });

        return redirect()->route('ns.goods-receipts.show', $id)->with('success', 'ยืนยันเข้าสต๊อกแล้ว บันทึกยอดค้างผู้ขายครั้งเดียว สามารถพิมพ์ใบรับสินค้าได้');
    }

    public function cancel(Request $request, int $id)
    {
        ns()->restrict('nexopos.update.procurements');
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        DB::transaction(function () use ($id, $data): void {
            $d = Procurement::lockForUpdate()->findOrFail($id);
            app(PurchaseOrderService::class)->authorize((int) $d->branch_id);
            abort_unless($d->receipt_source && $d->billing_approval_status === 'pending' && $d->delivery_status === 'pending', 422, 'ยกเลิกได้เฉพาะใบรับตรงที่ยังไม่เข้าสต๊อก');
            $d->billing_approval_status = 'cancelled';
            $d->receipt_note .= "\nยกเลิกโดย #".auth()->id().' เมื่อ '.now().' เหตุผล: '.$data['reason'];
            Procurement::withoutEvents(fn () => $d->save());
        });

        return redirect()->route('ns.goods-receipts.show',$id)->with('success','ยกเลิกใบรับแล้ว เก็บประวัติไว้ สต๊อกไม่เปลี่ยน');
    }
}
