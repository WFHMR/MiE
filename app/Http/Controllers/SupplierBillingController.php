<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Procurement;
use App\Models\Provider;
use App\Services\ProcurementWorkbook;
use App\Services\PurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierBillingController extends Controller
{
    public function index( Request $request ): View|StreamedResponse
    {
        ns()->restrict( 'nexopos.read.procurements' );
        $branches = app( PurchaseOrderService::class )->branches()->get();
        $data = $request->validate( ['branch_id' => 'nullable|integer|min:0', 'provider_id' => 'nullable|integer|exists:nexopos_providers,id', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'date_field' => 'nullable|in:invoice_date,billing_note_date,delivery_note_date,due_date', 'payment' => 'nullable|in:all,unpaid,paid', 'format' => 'nullable|in:html,xlsx,print'] );
        $branchId = (int) ( $data['branch_id'] ?? ( auth()->user()->branch_id ?: session( 'selected_branch_id' ) ) );
        if ( $branchId === 0 ) {
            abort_unless( ns()->hasRole( 'admin' ), 403 );
        } else {
            app( PurchaseOrderService::class )->authorize( $branchId );
        }
        $branchName = $branchId ? Branch::findOrFail( $branchId )->name : 'เอกสารเก่าไม่ระบุสาขา';
        $documents = collect();
        $provider = null;
        $dateField = $data['date_field'] ?? 'due_date';
        $dateLabels = ['due_date' => 'ครบกำหนดชำระ', 'invoice_date' => 'วันที่ใบกำกับภาษี', 'billing_note_date' => 'วันที่วางบิล', 'delivery_note_date' => 'วันที่ส่งสินค้า'];
        if ( $request->filled( 'provider_id' ) ) {
            $request->validate( ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'] );
            $provider = Provider::findOrFail( $data['provider_id'] );
            $query = Procurement::with( 'provider' )->whereNull( 'trial_archived_at' )->where( 'delivery_status', Procurement::STOCKED )->where( 'provider_id', $provider->id );
            $branchId ? $query->where( 'branch_id', $branchId ) : $query->whereNull( 'branch_id' );
            $query->whereDate( $dateField, '>=', $data['from'] )->whereDate( $dateField, '<=', $data['to'] );
            if ( ( $data['payment'] ?? 'unpaid' ) !== 'all' ) {
                $query->where( 'payment_status', $data['payment'] ?? 'unpaid' );
            }
            $documents = $query->orderBy( $dateField )->orderBy( 'id' )->get();
            foreach ( $documents as $document ) {
                $document->report_branch_name = $branchName;
            }
        }
        if ( ( $data['format'] ?? '' ) === 'xlsx' ) {
            abort_unless( $provider, 422, 'กรุณาเลือกซัพพลายเออร์และช่วงวันที่' );
            $book = app( ProcurementWorkbook::class )->create( $documents, 'สรุปวางบิลซัพพลายเออร์', $branchName . ' / ' . $provider->first_name . ' / ' . $dateLabels[$dateField] . ' ' . $data['from'] . ' - ' . $data['to'] );

            return response()->streamDownload( function () use ( $book ): void {
                ( new Xlsx( $book ) )->save( 'php://output' );
            }, 'supplier-billing.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] );
        }
        $view = ( $data['format'] ?? '' ) === 'print' ? 'supplier-billing-print' : 'supplier-billing';

        return view( 'pages.dashboard.procurements.' . $view, ['title' => 'สรุปวางบิลซัพพลายเออร์', 'description' => 'สรุปเอกสารที่ยืนยันรับเข้าแล้ว', 'branches' => $branches, 'branchId' => $branchId, 'branchName' => $branchName, 'providers' => Provider::orderBy( 'first_name' )->get(), 'provider' => $provider, 'documents' => $documents, 'data' => $data, 'dateField' => $dateField, 'dateLabels' => $dateLabels] );
    }
}
