<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\ProductUnitQuantity;
use App\Models\SalesDelivery;
use App\Models\SalesDocument;
use App\Models\SalesQuotation;
use App\Models\TaxGroup;
use App\Services\SalesWorkflowService;
use Illuminate\Http\Request;

class SalesWorkflowController extends Controller
{
    public function __construct(private SalesWorkflowService $service) {}

    private function scope(Request $r): array
    {
        $r->validate(['branch_id' => 'nullable|integer|min:1']);
        ns()->restrict('nexopos.read.orders');
        $branches = Branch::active()->when(! ns()->hasRole('admin'), fn ($q) => $q->whereKey(auth()->user()->branch_id ?: -1))->get();
        $branch = (int) ($r->input('branch_id') ?: (ns()->hasRole('admin') ? session('selected_branch_id') : auth()->user()->branch_id) ?: $branches->first()?->id);
        $this->service->authorize($branch);

        return compact('branches', 'branch');
    }

    public function quotations(Request $r)
    {
        $scope = $this->scope($r);

        return view('pages.dashboard.sales-workflow.quotations', $scope + ['title' => 'ใบเสนอราคา', 'documents' => SalesQuotation::where('branch_id', $scope['branch'])->latest('id')->paginate(20)->withQueryString()]);
    }

    public function form(Request $r, ?int $id = null)
    {
        $scope = $this->scope($r);
        $document = $id ? SalesQuotation::findOrFail($id) : null;
        if ($document) {
            $this->service->authorize((int) $document->branch_id, 'nexopos.update.orders');
            abort_unless($document->status === 'draft', 422);
            $scope['branch'] = (int) $document->branch_id;
        } else {
            $this->service->authorize($scope['branch'], 'nexopos.create.orders');
        }

        return view('pages.dashboard.sales-workflow.quote-form', $scope + ['title' => $id ? 'แก้ไขใบเสนอราคา' : 'สร้างใบเสนอราคา', 'document' => $document, 'taxGroups' => TaxGroup::all()]);
    }

    public function save(Request $r, ?int $id = null)
    {
        $q = $this->service->saveQuote($r->all(), $id);

        return redirect()->route('ns.sales.quote', $q->id)->with('success', 'บันทึกฉบับร่างแล้ว ยังไม่ตัดสต๊อกและยังไม่เกิดยอดขาย');
    }

    public function quote(int $id)
    {
        $document = SalesQuotation::findOrFail($id);
        $this->service->authorize((int) $document->branch_id);

        return view('pages.dashboard.sales-workflow.quote', ['title' => 'ใบเสนอราคา QT-'.$id, 'document' => $document, 'branchName' => Branch::find($document->branch_id)?->name, 'salesOrder' => SalesDocument::where('quotation_id', $id)->first()]);
    }

    public function quoteAction(Request $r, int $id)
    {
        $this->service->quoteAction($id, $r->all());

        return back()->with('success', 'บันทึกสถานะใบเสนอราคาแล้ว');
    }

    public function accept(Request $r, int $id)
    {
        $so = $this->service->accept($id, $r->all());

        return redirect()->route('ns.sales.order', $so->id)->with('success', 'สร้างใบสั่งขายและรายการเตรียมส่งแล้ว ยังไม่ตัดสต๊อก');
    }

    public function orders(Request $r)
    {
        $scope = $this->scope($r);

        return view('pages.dashboard.sales-workflow.orders', $scope + ['title' => 'ใบสั่งขาย', 'documents' => SalesDocument::where('branch_id', $scope['branch'])->latest('id')->paginate(20)->withQueryString()]);
    }

    public function order(int $id)
    {
        $document = SalesDocument::findOrFail($id);
        $this->service->authorize((int) $document->branch_id);

        return view('pages.dashboard.sales-workflow.order', ['title' => 'ใบสั่งขาย SO-'.$id, 'document' => $document, 'quotation' => SalesQuotation::findOrFail($document->quotation_id), 'delivery' => SalesDelivery::where('sales_document_id', $id)->firstOrFail()]);
    }

    public function orderAction(Request $r, int $id)
    {
        $r->validate(['action' => 'required|in:invoice,cancel,reserve,release', 'acknowledge' => 'accepted']);
        if ($r->input('action') === 'invoice') {
            $this->service->invoice($id);
        } elseif ($r->input('action') === 'reserve') {
            app(\App\Services\StockReservationService::class)->reserve($id);
        } elseif ($r->input('action') === 'release') {
            app(\App\Services\StockReservationService::class)->release($id);
        } else {
            $this->service->cancel($id);
        }

        return back()->with('success', 'บันทึกการดำเนินการแล้ว');
    }

    public function deliveries(Request $r)
    {
        $scope = $this->scope($r);

        return view('pages.dashboard.sales-workflow.deliveries', $scope + ['title' => 'จัดส่งสินค้า', 'documents' => SalesDelivery::where('branch_id', $scope['branch'])->latest('id')->paginate(20)->withQueryString()]);
    }

    public function delivery(int $id)
    {
        $document = SalesDelivery::findOrFail($id);
        $this->service->authorize((int) $document->branch_id);
        $so = SalesDocument::findOrFail($document->sales_document_id);

        return view('pages.dashboard.sales-workflow.delivery', ['title' => 'ใบจัดส่ง DO-'.$id, 'document' => $document, 'salesOrder' => $so, 'quotation' => SalesQuotation::findOrFail($so->quotation_id)]);
    }

    public function deliveryAction(Request $r, int $id)
    {
        $this->service->delivery($id, $r->all());

        return back()->with('success', 'บันทึกสถานะจัดส่งแล้ว');
    }

    public function reservations(Request $r)
    {
        $scope=$this->scope($r);
        $r->validate(['status'=>'nullable|in:active,released,consumed,all']);
        $status=$r->input('status','active');
        $entries=\Illuminate\Support\Facades\DB::table('nexopos_stock_reservations')->where('branch_id',$scope['branch'])->when($status!=='all',fn($q)=>$q->where('status',$status))->orderByDesc('id')->paginate(30)->withQueryString();
        return view('pages.dashboard.sales-workflow.reservations',$scope+['title'=>'จองสต๊อก / ประวัติการจอง','entries'=>$entries,'status'=>$status]);
    }

    public function lookup(Request $r)
    {
        $scope = $this->scope($r);
        $this->service->authorize($scope['branch'], 'nexopos.create.orders');
        $data = $r->validate(['search' => 'required|string|min:2|max:100', 'type' => 'required|in:customer,product']);
        $term = '%'.$data['search'].'%';
        if ($data['type'] === 'customer') {
            return response()->json(Customer::where(fn ($q) => $q->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('phone', 'like', $term))->limit(20)->get()->map(fn ($c) => ['id' => $c->id, 'label' => trim($c->first_name.' '.$c->last_name).' · '.$c->phone]));
        }

        return response()->json(ProductUnitQuantity::with(['product', 'unit'])->where('branch_id', $scope['branch'])->whereHas('product', fn ($q) => $q->where('type', '!=', 'grouped'))->where(fn ($q) => $q->where('barcode', 'like', $term)->orWhereHas('product', fn ($p) => $p->where('name', 'like', $term)->orWhere('sku', 'like', $term)))->limit(30)->get()->filter(fn ($u) => $u->unit && $u->product)->map(fn ($u) => ['id' => $u->id, 'label' => $u->product->name.' — '.$u->unit->name.' · '.$u->barcode, 'price' => app(\App\Services\ScheduledPriceService::class)->active((int)$u->product_id,(int)$u->unit_id)?->price ?? $u->sale_price])->values());
    }
}
