<?php

namespace App\Http\Middleware;

use App\Models\Procurement;
use App\Models\ProcurementProduct;
use App\Models\PurchaseReturn;
use App\Services\PurchaseOrderService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PurchaseReceiptScope
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! preg_match('#^(api|dashboard)/procurements(?:/|$)#', $request->path())) {
            return $next($request);
        }
        if (! $request->isMethod('GET') && (in_array($request->input('general.delivery_status'), ['delivered', 'stocked'], true) || $request->boolean('general.automatic_approval'))) {
            abort(422, 'ต้องตรวจนับและยืนยันผ่านใบสั่งซื้อ / รับสินค้า ไม่รับเข้าอัตโนมัติ');
        }
        $value = $request->route('procurement') ?? $request->route('id');
        $receipt = $value instanceof Procurement ? $value : (is_numeric($value) ? Procurement::find($value) : null);
        if (! $receipt && $request->route('product') instanceof ProcurementProduct) {
            $receipt = $request->route('product')->procurement;
        }
        if ($receipt && ! $request->isMethod('GET') && PurchaseReturn::where('procurement_id', $receipt->id)->where('status', '!=', 'cancelled')->exists()) {
            app(PurchaseOrderService::class)->authorize((int) $receipt->branch_id);
            abort(422, 'เอกสารนี้มีใบคืนสินค้า กรุณาจัดการใบคืนและชำระผ่านใบสำคัญจ่าย ห้ามแก้เอกสารรับเข้าต้นทาง');
        }
        if ($receipt?->trial_archived_at && ! $request->isMethod('GET')) {
            abort(403, 'เอกสารนี้อยู่ในรายการทดลอง ไม่สามารถแก้หรือลบ');
        }
        if ($receipt && ($receipt->purchase_order_id || $receipt->receipt_source)) {
            app(PurchaseOrderService::class)->authorize((int) $receipt->branch_id);
            if (! $request->isMethod('GET') && ! str_ends_with($request->path(), '/set-as-paid') && ! str_ends_with($request->path(), '/change-payment-status')) {
                abort(403, 'เอกสารรับเข้าจากใบสั่งซื้อยืนยันแล้ว ไม่สามารถแก้หรือลบผ่านหน้ารับเข้าเดิม');
            }
        }

        return $next($request);
    }
}
