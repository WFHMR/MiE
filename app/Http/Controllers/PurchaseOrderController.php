<?php

namespace App\Http\Controllers;

use App\Forms\ProcurementForm;
use App\Models\Procurement;
use App\Models\ProcurementProduct;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseReturn;
use App\Services\ProductService;
use App\Services\ProviderService;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $orders) {}

    private function order(int $id): PurchaseOrder
    {
        $order = PurchaseOrder::findOrFail($id);
        $this->orders->authorize((int) $order->branch_id);

        return $order;
    }

    public function trials(): View
    {
        abort_unless(ns()->hasRole('admin'), 403);

        return view('pages.dashboard.procurements.trials', ['title' => 'รายการทดลอง / เอกสารเก่า', 'description' => 'จัดเก็บเอกสารทดลองโดยไม่เปลี่ยนสต๊อกหรือบัญชี', 'documents' => Procurement::whereNull('purchase_order_id')->whereNull('receipt_source')->latest('id')->paginate(30)]);
    }

    public function archiveTrial(Request $request, int $id): RedirectResponse
    {
        abort_unless(ns()->hasRole('admin'), 403);
        $data = $request->validate(['action' => 'required|in:archive,restore', 'acknowledge' => 'accepted']);
        DB::transaction(function () use ($id, $data): void {
            $document = Procurement::lockForUpdate()->findOrFail($id);
            abort_if($document->purchase_order_id || $document->receipt_source, 422, 'เอกสารรับจริงจากใบสั่งซื้อไม่ใช่รายการทดลองเก่า');
            abort_if(PurchaseReturn::where('procurement_id', $id)->where('status', '!=', 'cancelled')->exists(), 422, 'เอกสารนี้มีใบคืนสินค้า ไม่สามารถจัดเก็บเป็นรายการทดลอง');
            DB::table('nexopos_procurements')->where('id', $id)->update([
                'trial_archived_at' => $data['action'] === 'archive' ? now() : null,
                'trial_archived_by' => $data['action'] === 'archive' ? auth()->id() : null,
            ]);
            app(ProviderService::class)->computeSummary($document->provider);
        });

        return redirect()->route('ns.purchase-trials')->with('success', 'จัดเก็บสถานะเอกสารแล้ว สต๊อกและรายการบัญชีไม่เปลี่ยนแปลง');
    }

    private function paperworkFields(): array
    {
        return ['invoice_reference' => 'เลขที่ใบกำกับภาษี', 'invoice_date' => 'วันที่ใบกำกับภาษี', 'delivery_note_number' => 'เลขที่ใบส่งสินค้า', 'delivery_note_date' => 'วันที่ใบส่งสินค้า', 'billing_note_number' => 'เลขที่ใบวางบิล', 'billing_note_date' => 'วันที่ใบวางบิล', 'contact_person' => 'ผู้ติดต่อ / ผู้รับเรื่อง', 'credit_days' => 'เครดิต (วัน)', 'due_date' => 'ครบกำหนดชำระ'];
    }

    private function paperworkDocument(int $id): Procurement
    {
        ns()->restrict('nexopos.update.procurements');
        $document = Procurement::findOrFail($id);
        abort_if($document->trial_archived_at, 403, 'เอกสารอยู่ในรายการทดลอง กรุณาคืนรายการก่อน');
        if ($document->branch_id) {
            $this->orders->authorize((int) $document->branch_id);
        } else {
            abort_unless(ns()->hasRole('admin'), 403, 'เอกสารเก่าไม่ระบุสาขา ต้องให้แอดมินตรวจสอบ');
        }

        return $document;
    }

    private function paperworkVersion(Procurement $document): string
    {
        return hash('sha256', json_encode($document->only(array_merge(array_keys($this->paperworkFields()), ['updated_at']))));
    }

    public function paperwork(int $id): View
    {
        $document = $this->paperworkDocument($id);

        return view('pages.dashboard.procurements.paperwork', ['title' => 'แก้ไขข้อมูลเอกสารรับเข้า', 'description' => 'เติมเลขที่และวันที่เอกสารภายหลังรับเข้า', 'document' => $document, 'fields' => $this->paperworkFields(), 'version' => $this->paperworkVersion($document)]);
    }

    public function savePaperwork(Request $request, int $id): RedirectResponse
    {
        $this->paperworkDocument($id);
        $rules = ['version' => 'required|string|size:64'];
        foreach ($this->paperworkFields() as $key => $label) {
            $rules[$key] = str_ends_with($key, '_date') ? 'nullable|date_format:Y-m-d' : ($key === 'credit_days' ? 'nullable|integer|min:0|max:3650' : 'nullable|string|max:150');
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($id, $data): void {
            $document = Procurement::lockForUpdate()->findOrFail($id);
            $this->paperworkDocument($id);
            if (! hash_equals($this->paperworkVersion($document), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'เอกสารเปลี่ยนแล้ว กรุณาเปิดหน้าใหม่ก่อนแก้ไข']);
            }
            $changes = Arr::only($data, array_keys($this->paperworkFields()));
            $before = $document->only(array_keys($changes));
            DB::table('nexopos_procurements')->where('id', $id)->update(array_merge($changes, ['updated_at' => now()]));
            Log::info('Procurement paperwork updated', ['procurement_id' => $id, 'actor_id' => auth()->id(), 'before' => $before, 'after' => $changes]);
        });

        return redirect()->route('ns.purchase-paperwork', $id)->with('success', 'บันทึกข้อมูลเอกสารแล้ว จำนวนสินค้าและสต๊อกไม่เปลี่ยน');
    }

    public function index(): View
    {
        ns()->restrict('nexopos.read.procurements');

        return view('pages.dashboard.procurements.purchase-orders', ['title' => 'ใบสั่งซื้อ / รับสินค้า', 'description' => 'สั่งซื้อ → ตรวจนับ → ยืนยันวางบิลและเข้าสต๊อก → สรุปวางบิล → ใบสำคัญจ่าย', 'orders' => PurchaseOrder::whereIn('branch_id', $this->orders->branches()->select('id'))->latest('id')->paginate(20)]);
    }

    public function create(?int $id = null): View
    {
        ns()->restrict('nexopos.create.procurements');
        $order = $id ? $this->order($id) : null;
        if ($id) {
            abort_if(PurchaseRequest::where('purchase_order_id', $id)->exists(), 422, 'ใบสั่งซื้อจากใบเสนอราคาที่อนุมัติแล้วไม่สามารถแก้รายการหรือราคา');
        }
        if ($order) {
            ns()->restrict('nexopos.update.procurements');
            abort_unless($order->status === 'pending', 422, 'รับสินค้าบางส่วนแล้ว ไม่สามารถแก้จำนวนสั่งเดิม');
        }

        return view('pages.dashboard.procurements.purchase-order-create', ['title' => $order ? 'แก้ไขใบสั่งซื้อ #'.$order->id : 'สร้างใบสั่งซื้อ', 'description' => 'ขั้นตอนที่ 1 — ยังไม่รับสินค้าและยังไม่เพิ่มสต๊อก', 'order' => $order]);
    }

    public function form(?int $id = null): array
    {
        ns()->restrict('nexopos.create.procurements');
        $order = $id ? $this->order($id) : null;
        if ($id) {
            abort_if(PurchaseRequest::where('purchase_order_id', $id)->exists(), 422, 'ใบสั่งซื้อจากใบเสนอราคาที่อนุมัติแล้วไม่สามารถแก้รายการหรือราคา');
        }
        $form = (new ProcurementForm)->form;
        $fields = collect($form['tabs']['general']['fields'])->whereIn('name', ['provider_id', 'purchase_order_number', 'delivery_time'])->values()->all();
        foreach ($fields as &$field) {
            if ($field['name'] === 'delivery_time') {
                $field['label'] = 'กำหนดส่งสินค้า';
                $field['description'] = 'วันที่คาดว่าจะได้รับสินค้า ไม่ใช่การยืนยันรับ';
                $field['value'] = $order?->expected_at ?? '';
            }
            if ($field['name'] === 'provider_id') {
                $field['value'] = $order?->provider_id ?? '';
                $field['label'] = 'ผู้ขาย';
                $field['description'] = 'เลือกผู้ขายสำหรับใบสั่งซื้อนี้';
            }
            if ($field['name'] === 'purchase_order_number') {
                $field['value'] = $order?->reference ?? '';
            }
        }
        unset($field);
        $fields[] = ['type' => 'select', 'name' => 'branch_id', 'label' => 'สาขาที่รับสินค้า', 'description' => 'ใบสั่งซื้อนี้จะรับเข้าเฉพาะสาขาที่เลือก', 'validation' => 'required', 'value' => $order?->branch_id ?? session('selected_branch_id'), 'options' => $this->orders->branches()->get()->map(fn ($b) => ['label' => $b->name, 'value' => $b->id])->all()];
        $form['main']['label'] = 'ชื่อใบสั่งซื้อ';
        $form['main']['description'] = 'ระบุชื่อเพื่อค้นหาและอ้างอิงใบสั่งซื้อ';
        $form['main']['value'] = $order?->name ?? '';
        $form['tabs']['general'] = ['label' => 'ข้อมูลใบสั่งซื้อ', 'fields' => $fields];
        $columns = [];
        foreach ($form['columns'] as $key => $column) {
            $columns[$key] = $column;
            if ($column['type'] === 'name') {
                $columns['current_stock'] = ['label' => 'สต๊อกปัจจุบัน', 'type' => 'current_stock'];
            }
        }
        $form['columns'] = $columns;
        $form['products'] = $order ? collect($order->lines)->map(function ($line) use ($order) {
            $product = Product::with(['unit_quantities' => fn ($q) => $q->where('branch_id', $order->branch_id)->with('unit')])->find($line['product_id']);

            if ($product) {
                $this->prepareProduct($product, (int) $order->branch_id, (int) $order->provider_id);
            }

            return array_merge($line, ['procurement' => array_merge($line, ['purchase_price_edit' => $line['purchase_price'], '$invalid' => $product === null]), 'unit_quantities' => $product?->unit_quantities ?? []]);
        })->all() : [];

        return $form;
    }

    public function search(Request $request): JsonResponse
    {
        ns()->restrict('nexopos.create.procurements');
        $data = $request->validate(['search' => 'required|string|max:150', 'provider_id' => 'required|integer|exists:nexopos_providers,id', 'branch_id' => 'required|integer']);
        $this->orders->authorize((int) $data['branch_id']);
        $term = trim($data['search']);
        $branch = (int) $data['branch_id'];
        $products = Product::where('provider_id', $data['provider_id'])->where('stock_management', 'enabled')->where('type', '!=', 'grouped')
            ->whereHas('unit_quantities', fn ($q) => $q->where('branch_id', $branch))
            ->where(function ($q) use ($term, $branch): void {
                $q->where('name', 'like', '%'.$term.'%')->orWhere('sku', 'like', '%'.$term.'%')->orWhere('barcode', $term)
                    ->orWhereHas('unit_quantities', fn ($u) => $u->where('branch_id', $branch)->where('barcode', $term));
            })->with(['unit_quantities' => fn ($q) => $q->where('branch_id', $branch)->with('unit')])->orderBy('name')->limit(30)->get();

        return response()->json($products->map(function ($product) use ($term, $branch, $data) {
            return $this->prepareProduct($product, $branch, (int) $data['provider_id'], $term);
        }));
    }

    private function prepareProduct(Product $product, int $branch, int $provider, string $term = ''): Product
    {
        $product->setRelation('unit_quantities', $product->unit_quantities->sortBy(fn ($u) => $u->barcode === $term ? 0 : 1)->values());
        $product->matched_unit_id = $product->unit_quantities->firstWhere('barcode', $term)?->unit_id;
        $previous = ProcurementProduct::where('product_id', $product->id)
            ->whereHas('procurement', fn ($q) => $q->where('branch_id', $branch)->where('provider_id', $provider)->where('delivery_status', 'stocked'))->latest('id')->first();
        $product->default_purchase_unit_id = $previous && $product->unit_quantities->contains('unit_id', $previous->unit_id) ? $previous->unit_id : null;
        foreach ($product->unit_quantities as $unit) {
            $last = ProcurementProduct::where('product_id', $product->id)->where('unit_id', $unit->unit_id)
                ->whereHas('procurement', fn ($q) => $q->where('branch_id', $branch)->where('provider_id', $provider)->where('delivery_status', 'stocked'))
                ->latest('id')->first();
            $unit->last_purchase_price = $last ? (float) ($last->tax_type === 'exclusive' ? $last->net_purchase_price : $last->gross_purchase_price) : null;
            $unit->purchase_tax_type = $last?->tax_type;
            if ($last === null && (float) $unit->cogs > 0) {
                $unit->last_purchase_price = (float) $unit->cogs;
                $unit->purchase_tax_type = 'inclusive';
            }
            $unit->purchase_tax_group_id = $last?->tax_group_id;
            $bearing = app(ProductService::class)->resolveStockBearingUnit($product->id, $unit->unit_id, $branch);
            $base = $product->unit_quantities->firstWhere('unit_id', $bearing['unit_id']);
            $unit->current_stock_quantity = $base?->quantity;
            $unit->current_stock_unit = $base?->unit?->name;
            $unit->stock_conversion_label = $bearing['unit_id'] !== $unit->unit_id && $base
                ? 'รับ 1 '.$unit->unit->name.' เพิ่มสต๊อก '.$bearing['factor'].' '.$base->unit->name.' อัตโนมัติ'
                : 'รับและเก็บสต๊อกในหน่วยนี้ ไม่ต้องเลือกแปลง';
        }

        return $product;
    }

    public function save(Request $request, ?int $id = null): JsonResponse
    {
        ns()->restrict('nexopos.create.procurements');
        if ($id) {
            abort_if(PurchaseRequest::where('purchase_order_id', $id)->exists(), 422, 'ใบสั่งซื้อจากใบเสนอราคาที่อนุมัติแล้วไม่สามารถแก้รายการหรือราคา');
            ns()->restrict('nexopos.update.procurements');
        }
        $data = $request->validate([
            'name' => 'required|string|max:150', 'general.branch_id' => 'required|integer', 'general.provider_id' => 'required|integer|exists:nexopos_providers,id',
            'general.purchase_order_number' => 'nullable|string|max:100', 'general.delivery_time' => 'nullable|date_format:Y-m-d',
            'products' => 'required|array|min:1|max:200', 'products.*.product_id' => 'required|integer|exists:nexopos_products,id', 'products.*.unit_id' => 'required|integer|exists:nexopos_units,id',
            'products.*.quantity' => 'required|numeric|min:0.00001|max:9999999', 'products.*.purchase_price' => 'required|numeric|min:0|max:9999999',
            'products.*.gross_purchase_price' => 'required|numeric|min:0', 'products.*.net_purchase_price' => 'required|numeric|min:0', 'products.*.tax_value' => 'required|numeric|min:0', 'products.*.total_purchase_price' => 'required|numeric|min:0',
            'products.*.tax_type' => 'required|in:inclusive,exclusive', 'products.*.tax_group_id' => 'nullable|integer|min:0', 'version' => $id ? 'required|integer' : 'nullable',
        ]);
        $this->orders->authorize((int) $data['general']['branch_id']);
        $order = DB::transaction(function () use ($id, $data): PurchaseOrder {
            $order = $id ? PurchaseOrder::lockForUpdate()->findOrFail($id) : new PurchaseOrder;
            if ($id) {
                $this->orders->authorize((int) $order->branch_id);
                if ($order->status !== 'pending' || $order->version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['order' => 'ใบสั่งซื้อเปลี่ยนแล้ว กรุณาเปิดใหม่']);
                }
            }
            $order->name = $data['name'];
            $order->branch_id = $data['general']['branch_id'];
            $order->provider_id = $data['general']['provider_id'];
            $order->reference = $data['general']['purchase_order_number'] ?? null;
            $order->expected_at = $data['general']['delivery_time'] ?? null;
            foreach ($data['products'] as $line) {
                if (! Product::whereKey($line['product_id'])->where('provider_id', $data['general']['provider_id'])->exists()) {
                    throw ValidationException::withMessages(['products' => 'สินค้าไม่ตรงกับผู้ขายที่เลือก กรุณาตรวจผู้ขายในข้อมูลสินค้า']);
                }
            }
            $order->lines = $this->orders->normalize($data['products']);
            $order->version = $id ? $order->version + 1 : 1;
            if (! $id) {
                $order->author_id = auth()->id();
                $order->receipts = [];
                $order->status = 'pending';
            }
            $order->save();

            return $order;
        });

        return response()->json(['status' => 'success', 'message' => 'บันทึกใบสั่งซื้อแล้ว ยังไม่เพิ่มสต๊อก', 'id' => $order->id]);
    }

    public function show(int $id): View
    {
        ns()->restrict('nexopos.read.procurements');
        $order = $this->order($id);

        return view('pages.dashboard.procurements.purchase-orders', ['title' => 'รับสินค้าตามใบสั่งซื้อ #'.$id, 'description' => 'ขั้นตอนที่ 2 — บันทึกจำนวนตรวจนับ ส่งให้ฝ่ายวางบิลยืนยันก่อนเข้าสต๊อก', 'order' => $order, 'purchaseRequest' => PurchaseRequest::where('purchase_order_id', $order->id)->first(), 'branch' => $this->orders->branches()->findOrFail($order->branch_id)]);
    }

    public function receive(Request $request, int $id): RedirectResponse
    {
        ns()->restrict(['nexopos.create.procurements', 'nexopos.update.procurements']);
        $data = $request->validate(['version' => 'required|integer', 'quantities' => 'required|array|min:1|max:200', 'quantities.*' => 'nullable|numeric|min:0|max:9999999|decimal:0,5', 'acknowledge' => 'accepted',
            'received_at' => 'required|date_format:Y-m-d', 'invoice_reference' => 'nullable|string|max:100', 'invoice_date' => 'nullable|date_format:Y-m-d',
            'delivery_note_number' => 'nullable|string|max:100', 'delivery_note_date' => 'nullable|date_format:Y-m-d', 'billing_note_number' => 'nullable|string|max:100', 'billing_note_date' => 'nullable|date_format:Y-m-d',
            'contact_person' => 'nullable|string|max:150', 'credit_days' => 'nullable|integer|min:0|max:3650', 'due_date' => 'nullable|date_format:Y-m-d', 'payment_status' => 'nullable|in:unpaid']);
        $receipt = $this->orders->receive($id, $data);

        return redirect()->route('ns.purchase-orders.show', $id)->with('success', 'บันทึกยอดตรวจนับแล้ว รอฝ่ายวางบิลยืนยันก่อนเพิ่มสต๊อก เอกสาร #'.$receipt->id);
    }

    public function billingQueue(Request $request): View
    {
        ns()->restrict('nexopos.read.procurements');
        $branches = $this->orders->branches()->get();
        $branch = (int) ($request->input('branch_id') ?: (ns()->hasRole('admin') ? session('selected_branch_id') : auth()->user()->branch_id) ?: $branches->first()?->id);
        $this->orders->authorize($branch);

        $data = $request->validate(['status' => 'nullable|in:pending,approved,all', 'purchase_order_id' => 'nullable|integer|min:1']);
        $status = $data['status'] ?? 'pending';
        $orderId = $data['purchase_order_id'] ?? null;
        $documents = Procurement::with('provider')->where('branch_id', $branch)->whereNull('trial_archived_at')
            ->when($orderId, fn ($query) => $query->where('purchase_order_id', $orderId))
            ->when($status === 'pending', fn ($query) => $query->where('billing_approval_status', 'pending'))
            ->when($status === 'approved', fn ($query) => $query->where(fn ($q) => $q->where('billing_approval_status', 'approved')->orWhere('delivery_status', Procurement::STOCKED)))
            ->latest('id')->paginate(30)->withQueryString();

        return view('pages.dashboard.procurements.billing-queue', ['title' => 'ตรวจรับ / ยืนยันวางบิล', 'description' => 'ตรวจนับ → ยืนยันวางบิลและเข้าสต๊อก → สรุปวางบิล → ใบสำคัญจ่าย', 'branches' => $branches, 'branch' => $branch, 'documents' => $documents, 'status' => $status, 'orderId' => $orderId]);
    }

    public function confirmBilling(Request $request, int $id): RedirectResponse
    {
        $request->validate(['acknowledge' => 'required|accepted', 'version' => 'required|string|size:64']);
        DB::transaction(function () use ($request, $id): void {
            $document = Procurement::lockForUpdate()->findOrFail($id);
            $this->paperworkDocument($id);
            if ($document->billing_approval_status !== 'approved' && ! hash_equals($this->paperworkVersion($document), $request->input('version'))) {
                throw ValidationException::withMessages(['version' => 'ข้อมูลเอกสารเปลี่ยนแล้ว กรุณาเปิดใหม่และตรวจอีกครั้ง']);
            }
            $this->orders->approveBilling($id);
        });

        return redirect()->route('ns.purchase-paperwork', $id)->with('success', 'ยืนยันวางบิลและรับเข้าสต๊อกแล้ว พร้อมแสดงในสรุปวางบิลและเตรียมจ่าย');
    }
}
