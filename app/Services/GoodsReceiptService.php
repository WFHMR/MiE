<?php

namespace App\Services;

use App\Models\Procurement;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\TaxGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GoodsReceiptService
{
    public function create(array $input): Procurement
    {
        ns()->restrict(['nexopos.create.procurements', 'nexopos.read.procurements']);
        $data = Validator::make($input, [
            'request_key' => 'required|uuid', 'branch_id' => 'required|integer|min:1', 'provider_id' => 'required|integer|exists:nexopos_providers,id',
            'source' => 'required|in:invoice,no_bill', 'received_at' => 'required|date_format:Y-m-d|before_or_equal:'.now('Asia/Bangkok')->toDateString(),
            'invoice_reference' => 'nullable|required_if:source,invoice|string|max:100', 'invoice_date' => 'nullable|required_if:source,invoice|date_format:Y-m-d',
            'note' => 'required|string|max:2000', 'credit_days' => 'required|integer|min:0|max:3650',
            'lines' => 'required|array|min:1|max:200', 'lines.*.row_id' => 'required|integer|distinct', 'lines.*.quantity' => 'required|numeric|min:0.00001|max:9999999|decimal:0,5',
            'lines.*.price' => 'required|numeric|min:0|max:9999999|decimal:0,5', 'lines.*.tax_group_id' => 'nullable|integer|exists:nexopos_taxes_groups,id',
        ])->validate();
        app(PurchaseOrderService::class)->authorize((int) $data['branch_id']);

        return DB::transaction(function () use ($data): Procurement {
            Provider::whereKey($data['provider_id'])->lockForUpdate()->firstOrFail();
            $prior = Procurement::where('receipt_request_key', $data['request_key'])->first();
            if ($prior) {
                abort_unless((int) $prior->branch_id === (int) $data['branch_id'] && (int) $prior->author_id === auth()->id(), 403);

                return $prior;
            }
            if ($data['source'] === 'invoice' && Procurement::where('provider_id', $data['provider_id'])->where('branch_id', $data['branch_id'])->where('invoice_reference', trim($data['invoice_reference']))->whereNull('trial_archived_at')->where(fn ($q) => $q->whereNull('billing_approval_status')->orWhere('billing_approval_status', '!=', 'cancelled'))->exists()) {
                throw ValidationException::withMessages(['invoice_reference' => 'เลขเอกสารนี้ของผู้ขายมีอยู่ในสาขานี้แล้ว กรุณาตรวจประวัติก่อนรับซ้ำ']);
            }
            $products = [];
            foreach ($data['lines'] as $line) {
                $row = ProductUnitQuantity::with(['product', 'unit'])->where('branch_id', $data['branch_id'])->findOrFail($line['row_id']);
                abort_unless($row->product && $row->unit && $row->product->stock_management === 'enabled' && $row->product->type !== 'grouped' && (int) $row->product->unit_group === (int) $row->unit->group_id, 422, 'สินค้า/หน่วยไม่รองรับรับสต๊อก');
                if ($data['source'] === 'no_bill' && ! empty($line['tax_group_id'])) {
                    throw ValidationException::withMessages(['lines' => 'รับแบบไม่มีบิลให้บันทึกต้นทุนรวมโดยไม่แยกภาษีซื้อก่อน']);
                }
                $gross = (float) $line['price'];
                $rate = ! empty($line['tax_group_id']) ? (float) TaxGroup::findOrFail($line['tax_group_id'])->taxes()->sum('rate') : 0;
                $net = $gross / (1 + $rate / 100);
                $products[] = ['product_id' => $row->product_id, 'unit_id' => $row->unit_id, 'quantity' => $line['quantity'], 'purchase_price' => $gross, 'gross_purchase_price' => $gross, 'net_purchase_price' => round($net, 5), 'tax_type' => 'inclusive', 'tax_group_id' => $line['tax_group_id'] ?? null, 'tax_value' => round(($gross - $net) * $line['quantity'], 5), 'total_purchase_price' => round($gross * $line['quantity'], 5)];
            }
            $previous = session('selected_branch_id');
            session(['selected_branch_id' => (int) $data['branch_id']]);
            try {
                $result = app(ProcurementService::class)->create(['name' => 'ใบรับสินค้า '.($data['source'] === 'no_bill' ? 'ไม่มีบิล' : 'ตามใบแจ้งหนี้'), 'general' => [
                    'branch_id' => $data['branch_id'], 'provider_id' => $data['provider_id'], 'delivery_time' => $data['received_at'], 'delivery_status' => 'pending', 'automatic_approval' => 0, 'billing_approval_status' => 'pending', 'payment_status' => 'unpaid',
                    'receipt_source' => $data['source'], 'receipt_request_key' => $data['request_key'], 'receipt_note' => $data['note'], 'credit_days' => $data['credit_days'],
                    'invoice_reference' => $data['source'] === 'invoice' ? trim($data['invoice_reference']) : null, 'invoice_date' => $data['source'] === 'invoice' ? $data['invoice_date'] : null,
                ], 'products' => $products]);

                return $result['data']['procurement'];
            } finally {
                if ($previous === null) {
                    session()->forget('selected_branch_id');
                } else {
                    session(['selected_branch_id' => $previous]);
                }
            }
        });
    }
}
