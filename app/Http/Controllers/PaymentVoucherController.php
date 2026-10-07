<?php

namespace App\Http\Controllers;

use App\Models\PaymentVoucher;
use App\Models\Procurement;
use App\Models\Provider;
use App\Services\PaymentVoucherService;
use App\Services\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PaymentVoucherController extends Controller
{
    public function index(Request $request): View
    {
        ns()->restrict('nexopos.read.procurements');
        $data = $request->validate(['branch_id' => 'nullable|integer', 'provider_id' => 'nullable|integer|exists:nexopos_providers,id', 'mode' => 'nullable|in:payment,recorded']);
        $branchId = (int) ($data['branch_id'] ?? (auth()->user()->branch_id ?: session('selected_branch_id')));
        app(PurchaseOrderService::class)->authorize($branchId);
        $documents = collect();
        $mode = $data['mode'] ?? 'payment';
        if (! empty($data['provider_id'])) {
            $documents = Procurement::where('branch_id', $branchId)->where('provider_id', $data['provider_id'])->whereNull('trial_archived_at')->where('delivery_status', Procurement::STOCKED)->where('payment_status', $mode === 'recorded' ? 'paid' : 'unpaid')->whereRaw('cost - purchase_return_credit > 0')->whereNotIn('id', DB::table('nexopos_payment_voucher_receipts')->select('procurement_id'))->orderBy('id')->get();
        }

        return view('pages.dashboard.procurements.payment-vouchers', ['title' => 'ใบสำคัญจ่าย', 'description' => 'สร้างเอกสารแล้วตรวจสอบก่อนยืนยันชำระ', 'branches' => app(PurchaseOrderService::class)->branches()->get(), 'branchId' => $branchId, 'providers' => Provider::orderBy('first_name')->get(), 'data' => $data, 'mode' => $mode, 'documents' => $documents, 'vouchers' => PaymentVoucher::where('branch_id', $branchId)->latest('id')->paginate(20)->withQueryString()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'branch_id' => 'required|integer', 'provider_id' => 'required|integer|exists:nexopos_providers,id', 'mode' => 'required|in:payment,recorded', 'paid_at' => 'required|date_format:Y-m-d|before_or_equal:'.now('Asia/Bangkok')->format('Y-m-d'), 'method' => 'required|in:transfer,cash,cheque', 'reference' => 'nullable|required_unless:method,cash|string|max:150', 'notes' => 'nullable|string|max:1000', 'receipts' => 'required|array|min:1|max:100', 'receipts.*' => 'required|integer|distinct']);
        $voucher = app(PaymentVoucherService::class)->create($data);

        return redirect()->route('ns.payment-vouchers.show', $voucher->id);
    }

    public function show(int $id): View
    {
        ns()->restrict('nexopos.read.procurements');
        $voucher = PaymentVoucher::findOrFail($id);
        app(PurchaseOrderService::class)->authorize((int) $voucher->branch_id);

        return view('pages.dashboard.procurements.payment-voucher', ['voucher' => $voucher]);
    }

    public function confirm(Request $request, int $id): RedirectResponse
    {
        $request->validate(['acknowledge' => 'required|accepted']);
        app(PaymentVoucherService::class)->confirm($id);

        return redirect()->route('ns.payment-vouchers.show', $id)->with('success', 'ยืนยันใบสำคัญจ่ายแล้ว');
    }

    public function cancel(int $id): RedirectResponse
    {
        app(PaymentVoucherService::class)->cancel($id);

        return redirect()->route('ns.payment-vouchers.show', $id);
    }
}
