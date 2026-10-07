<?php

namespace App\Services;

use App\Models\Procurement;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseReturn;
use App\Models\SupplierQuotation;
use App\Models\TaxGroup;
use App\Models\Transaction;
use App\Models\TransactionAccount;
use App\Models\TransactionActionRule;
use App\Models\TransactionHistory;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PurchasingWorkflowService
{
    public function authorize(int $branch, string $permission = 'nexopos.read.procurements'): void
    {
        ns()->restrict($permission);
        app(PurchaseOrderService::class)->authorize($branch);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['purchasing' => $message]);
    }

    public function request(array $input): PurchaseRequest
    {
        $data = Validator::make($input, [
            'request_key' => 'required|uuid', 'branch_id' => 'required|integer', 'name' => 'required|string|max:150',
            'department' => 'nullable|string|max:150', 'reason' => 'required|string|max:2000', 'needed_at' => 'nullable|date_format:Y-m-d',
            'lines' => 'required|array|min:1|max:200', 'lines.*.product_id' => 'required|integer', 'lines.*.unit_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|min:0.00001|max:999999|decimal:0,5',
        ])->validate();
        $this->authorize((int) $data['branch_id'], 'nexopos.create.procurements');

        return DB::transaction(function () use ($data): PurchaseRequest {
            $existing = PurchaseRequest::where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->author_id === auth()->id() && (int) $existing->branch_id === (int) $data['branch_id'], 403);

                return $existing;
            }
            $lines = app(PurchaseOrderService::class)->normalize($data['lines']);

            return PurchaseRequest::create(array_merge($data, ['lines' => $lines, 'author_id' => auth()->id(), 'status' => 'submitted', 'version' => 1]));
        });
    }

    public function decide(int $id, array $input): PurchaseRequest
    {
        $data = Validator::make($input, ['version' => 'required|integer', 'action' => 'required|in:approved,rejected', 'decision_note' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($id, $data): PurchaseRequest {
            $r = PurchaseRequest::lockForUpdate()->findOrFail($id);
            $this->authorize((int) $r->branch_id, 'nexopos.update.procurements');
            if ($r->status !== 'submitted' || $r->version !== (int) $data['version']) {
                $this->fail('ใบขอซื้อถูกพิจารณาแล้ว กรุณาเปิดหน้าใหม่');
            }
            $r->update(['status' => $data['action'], 'decision_note' => $data['decision_note'], 'approved_by' => auth()->id(), 'approved_at' => now(), 'version' => $r->version + 1]);

            return $r;
        });
    }

    public function quote(int $id, array $input): SupplierQuotation
    {
        $data = Validator::make($input, [
            'provider_id' => 'required|integer|exists:nexopos_providers,id', 'reference' => 'required|string|max:100',
            'valid_until' => 'required|date_format:Y-m-d', 'delivery_date' => 'nullable|date_format:Y-m-d',
            'credit_days' => 'required|integer|min:0|max:3650', 'terms' => 'nullable|string|max:3000',
            'prices' => 'required|array|min:1|max:200', 'prices.*' => 'required|numeric|min:0|max:9999999|decimal:0,5',
            'tax_type' => 'required|in:inclusive,exclusive', 'tax_group_id' => 'nullable|integer|exists:nexopos_taxes_groups,id',
        ])->validate();

        return DB::transaction(function () use ($id, $data): SupplierQuotation {
            $r = PurchaseRequest::lockForUpdate()->findOrFail($id);
            $this->authorize((int) $r->branch_id, 'nexopos.create.procurements');
            if ($r->status !== 'approved') {
                $this->fail('ต้องอนุมัติใบขอซื้อก่อน และต้องยังไม่ได้สร้างใบสั่งซื้อ');
            }
            if (SupplierQuotation::where('purchase_request_id', $id)->where('provider_id', $data['provider_id'])->where('reference', $data['reference'])->exists()) {
                $this->fail('บันทึกเลขที่ใบเสนอราคานี้แล้ว หากเป็นฉบับแก้ไขให้ใช้เลขที่ฉบับใหม่');
            }
            if (array_keys($data['prices']) !== array_keys($r->lines)) {
                $this->fail('กรุณากรอกราคาให้ครบทุกสินค้าตามใบขอซื้อ');
            }
            $rate = ! empty($data['tax_group_id']) ? (float) TaxGroup::findOrFail($data['tax_group_id'])->taxes()->sum('rate') : 0;
            $lines = [];
            foreach ($r->lines as $i => $line) {
                $price = (float) $data['prices'][$i];
                $gross = $data['tax_type'] === 'exclusive' ? $price * (1 + $rate / 100) : $price;
                $net = $data['tax_type'] === 'inclusive' ? $price / (1 + $rate / 100) : $price;
                $lines[] = array_merge($line, ['purchase_price' => round($gross, 5), 'gross_purchase_price' => round($gross, 5), 'net_purchase_price' => round($net, 5), 'tax_type' => $data['tax_type'], 'tax_group_id' => $data['tax_group_id'] ?? null, 'tax_value' => round(($gross - $net) * $line['quantity'], 5), 'total_purchase_price' => round($gross * $line['quantity'], 5)]);
            }
            unset($data['prices'], $data['tax_type'], $data['tax_group_id']);

            return SupplierQuotation::create(array_merge($data, ['purchase_request_id' => $id, 'author_id' => auth()->id(), 'lines' => $lines, 'total' => collect($lines)->sum('total_purchase_price')]));
        });
    }

    public function selectQuote(int $request, int $quote): PurchaseOrder
    {
        return DB::transaction(function () use ($request, $quote): PurchaseOrder {
            $r = PurchaseRequest::lockForUpdate()->findOrFail($request);
            $this->authorize((int) $r->branch_id, 'nexopos.update.procurements');
            ns()->restrict('nexopos.create.procurements');
            if ($r->purchase_order_id && (int) $r->selected_quote_id === $quote) {
                return PurchaseOrder::findOrFail($r->purchase_order_id);
            }
            $q = SupplierQuotation::where('purchase_request_id', $r->id)->findOrFail($quote);
            if ($r->status !== 'approved' || $q->valid_until < now('Asia/Bangkok')->toDateString()) {
                $this->fail('ใบขอซื้อไม่พร้อมสั่งซื้อ หรือใบเสนอราคาหมดอายุแล้ว');
            }
            $po = (new PurchaseOrder)->forceFill(['name' => $r->name, 'reference' => mb_substr('PR-'.$r->id.' / '.$q->reference, 0, 100), 'branch_id' => $r->branch_id, 'provider_id' => $q->provider_id, 'author_id' => auth()->id(), 'expected_at' => $q->delivery_date, 'status' => 'pending', 'version' => 1, 'lines' => app(PurchaseOrderService::class)->normalize($q->lines), 'receipts' => []]);
            $po->save();
            $r->update(['status' => 'ordered', 'selected_quote_id' => $q->id, 'purchase_order_id' => $po->id, 'version' => $r->version + 1]);

            return $po;
        });
    }

    public function createReturn(array $input): PurchaseReturn
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'procurement_id' => 'required|integer', 'mode' => 'required|in:credit,refund,exchange', 'reason' => 'required|string|max:2000', 'quantities' => 'required|array|min:1|max:200', 'quantities.*' => 'nullable|numeric|min:0|max:999999|decimal:0,5'])->validate();

        return DB::transaction(function () use ($data): PurchaseReturn {
            $receipt = Procurement::lockForUpdate()->findOrFail($data['procurement_id']);
            $this->authorize((int) $receipt->branch_id, 'nexopos.create.procurements');
            $existing = PurchaseReturn::where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->author_id === auth()->id() && (int) $existing->procurement_id === (int) $receipt->id, 403);

                return $existing;
            }
            $this->receiptReturnable($receipt, $data['mode']);
            $lines = [];
            $source = $receipt->products->keyBy('id');
            foreach ($data['quantities'] as $id => $qty) {
                if (! $source->has($id)) {
                    $this->fail('สินค้าคืนต้องอยู่ในเอกสารรับเข้าที่เลือก');
                }
                if ((float) $qty <= 0) {
                    continue;
                }
                $p = $source[$id];
                if ((float) $qty > (float) $p->quantity) {
                    $this->fail('จำนวนคืนเกินจำนวนรับเข้า');
                }
                $lines[] = ['receipt_line_id' => $p->id, 'product_id' => $p->product_id, 'unit_id' => $p->unit_id, 'name' => $p->name, 'unit_name' => Unit::find($p->unit_id)?->name, 'quantity' => (float) $qty, 'value' => round((float) $p->total_purchase_price * (float) $qty / (float) $p->quantity, 5)];
            }
            if (! $lines) {
                $this->fail('กรอกจำนวนคืนอย่างน้อยหนึ่งรายการ');
            }

            return PurchaseReturn::create(['request_key' => $data['request_key'], 'procurement_id' => $receipt->id, 'branch_id' => $receipt->branch_id, 'provider_id' => $receipt->provider_id, 'author_id' => auth()->id(), 'mode' => $data['mode'], 'reason' => $data['reason'], 'status' => 'draft', 'lines' => $lines, 'total' => collect($lines)->sum('value')]);
        });
    }

    private function receiptReturnable(Procurement $r, string $mode): void
    {
        if ($r->trial_archived_at || $r->delivery_status !== Procurement::STOCKED) {
            $this->fail('คืนได้เฉพาะเอกสารรับเข้าสต๊อกแล้ว ไม่ใช่เอกสารทดลอง');
        }
        if (($mode === 'credit' && $r->payment_status !== 'unpaid') || ($mode === 'refund' && $r->payment_status !== 'paid')) {
            $this->fail('ลดหนี้ใช้กับบิลยังไม่ชำระ รับเงินคืนใช้กับบิลชำระแล้ว กรุณาเลือกให้ตรงกับบิล');
        }
    }

    public function confirmReturn(int $id): PurchaseReturn
    {
        return DB::transaction(function () use ($id): PurchaseReturn {
            $r = PurchaseReturn::lockForUpdate()->findOrFail($id);
            $this->authorize((int) $r->branch_id, 'nexopos.update.procurements');
            if (in_array($r->status, ['confirmed', 'settled'], true)) {
                return $r;
            }
            if ($r->status !== 'draft') {
                $this->fail('ยืนยันได้เฉพาะใบคืนฉบับร่าง');
            }
            $receipt = Procurement::lockForUpdate()->findOrFail($r->procurement_id);
            if ((int) $receipt->branch_id !== (int) $r->branch_id || (int) $receipt->provider_id !== (int) $r->provider_id) {
                $this->fail('สาขาหรือผู้ขายในเอกสารต้นทางเปลี่ยน กรุณาตรวจสอบก่อนดำเนินการ');
            }
            $this->receiptReturnable($receipt, $r->mode);
            $previous = PurchaseReturn::where('procurement_id', $receipt->id)->whereIn('status', ['confirmed', 'settled'])->get()->flatMap(fn ($d) => $d->lines);
            $lines = $r->lines;
            foreach ($lines as &$line) {
                $source = $receipt->products()->findOrFail($line['receipt_line_id']);
                $returned = $previous->where('receipt_line_id', $source->id)->sum('quantity');
                if (round($returned + $line['quantity'], 5) > (float) $source->quantity) {
                    $this->fail('จำนวนคืนสะสมเกินจำนวนที่เคยรับเข้า: '.$line['name']);
                }
                $currentValue = round((float) $source->total_purchase_price * $line['quantity'] / (float) $source->quantity, 5);
                if ($currentValue !== (float) $line['value'] || (int) $source->product_id !== (int) $line['product_id'] || (int) $source->unit_id !== (int) $line['unit_id']) {
                    $this->fail('เอกสารต้นทางเปลี่ยน กรุณาสร้างใบคืนใหม่');
                }
                $bearing = app(ProductService::class)->resolveStockBearingUnit($line['product_id'], $line['unit_id'], (int) $r->branch_id);
                $row = ProductUnitQuantity::where('branch_id', $r->branch_id)->where('product_id', $line['product_id'])->where('unit_id', $bearing['unit_id'])->lockForUpdate()->first();
                $quantity = round($line['quantity'] * $bearing['factor'], 5);
                if (! $row || $quantity <= 0 || (float) $row->quantity < $quantity) {
                    $this->fail('สต๊อกสาขานี้ไม่พอคืน: '.$line['name']);
                }
                $line['stock_row_id'] = $row->id;
                $line['stock_unit_id'] = $row->unit_id;
                $line['stock_quantity'] = $quantity;
                $this->moveReturnStock($r, $line, false);
            }
            unset($line);
            $r->update(['lines' => $lines, 'status' => 'confirmed', 'confirmed_by' => auth()->id(), 'confirmed_at' => now()]);

            return $r;
        });
    }

    private function moveReturnStock(PurchaseReturn $r, array $line, bool $incoming): void
    {
        $row = ProductUnitQuantity::lockForUpdate()->findOrFail($line['stock_row_id']);
        if ((int) $row->branch_id !== (int) $r->branch_id || (int) $row->product_id !== (int) $line['product_id'] || (int) $row->unit_id !== (int) $line['stock_unit_id']) {
            $this->fail('หน่วยสต๊อกเปลี่ยน กรุณาตรวจข้อมูลสินค้าก่อนดำเนินการ');
        }
        $before = (float) $row->quantity;
        $after = round($before + ($incoming ? 1 : -1) * $line['stock_quantity'], 5);
        if ($after < (float) $row->reserved_quantity) {
            $this->fail('สต๊อกพร้อมใช้ไม่เพียงพอ มีสินค้าจองไว้ กรุณาตรวจยอดจองก่อน');
        }
        $row->quantity = $after;
        $row->save();
        $history = app(ProductService::class)->recordStockHistory(product_id: $row->product_id, action: $incoming ? ProductHistory::ACTION_ADDED : ProductHistory::ACTION_REMOVED, unit_id: $row->unit_id, unit_price: $line['value'] / $line['stock_quantity'], quantity: $line['stock_quantity'], total_price: $line['value'], author: auth()->id(), procurement_id: $r->procurement_id, procurement_product_id: $line['receipt_line_id'], old_quantity: $before, new_quantity: $after, branch_id: $r->branch_id);
        $history->description = 'คืนซัพพลายเออร์ RT-'.$r->id.($incoming ? ' รับสินค้าทดแทน' : ' ส่งคืน');
        $history->save();
    }

    public function settleReturn(int $id, array $input): PurchaseReturn
    {
        $data = Validator::make($input, ['reference' => 'required|string|max:150', 'note' => 'required|string|max:2000', 'acknowledge' => 'accepted', 'debit_account_id' => 'nullable|required_with:credit_account_id|integer|exists:nexopos_transactions_accounts,id', 'credit_account_id' => 'nullable|required_with:debit_account_id|integer|different:debit_account_id|exists:nexopos_transactions_accounts,id'])->validate();

        return DB::transaction(function () use ($id, $data): PurchaseReturn {
            $r = PurchaseReturn::lockForUpdate()->findOrFail($id);
            $this->authorize((int) $r->branch_id, 'nexopos.update.procurements');
            if ($r->status === 'settled') {
                return $r;
            }
            if ($r->status !== 'confirmed') {
                $this->fail('ต้องยืนยันส่งสินค้าคืนก่อน');
            }
            $receipt = Procurement::lockForUpdate()->findOrFail($r->procurement_id);
            if ((int) $receipt->branch_id !== (int) $r->branch_id || (int) $receipt->provider_id !== (int) $r->provider_id) {
                $this->fail('สาขาหรือผู้ขายในเอกสารต้นทางเปลี่ยน กรุณาตรวจสอบก่อนดำเนินการ');
            }
            $this->receiptReturnable($receipt, $r->mode);
            if ($r->mode === 'exchange') {
                foreach ($r->lines as $line) {
                    $this->moveReturnStock($r, $line, true);
                }
            } else {
                if ($r->mode === 'credit' && (float) $r->total > $receipt->payable_total) {
                    $this->fail('มูลค่าลดหนี้เกินยอดค้างจ่าย');
                }
                $this->postReturnAccounting($r, $data);
                if ($r->mode === 'credit') {
                    DB::table('nexopos_procurements')->where('id', $receipt->id)->update(['purchase_return_credit' => round((float) $receipt->purchase_return_credit + (float) $r->total, 5), 'updated_at' => now()]);
                }
            }
            $r->update(['status' => 'settled', 'settled_by' => auth()->id(), 'settled_at' => now(), 'settlement_reference' => $data['reference'], 'settlement_note' => $data['note']]);
            app(ProviderService::class)->computeSummary($receipt->provider);

            return $r;
        });
    }

    private function postReturnAccounting(PurchaseReturn $r, array $data): void
    {
        $pair = $this->accountingPair($r->mode === 'credit' ? TransactionActionRule::RULE_PROCUREMENT_UNPAID : TransactionActionRule::RULE_PROCUREMENT_PAID);
        if (! $pair && $r->mode === 'refund') {
            // Compose purchase-on-credit and settlement only when their payable legs cancel.
            $debt = $this->accountingPair(TransactionActionRule::RULE_PROCUREMENT_UNPAID);
            $payment = $this->accountingPair(TransactionActionRule::RULE_PROCUREMENT_FROM_UNPAID_TO_PAID);
            $net = [];
            if ($debt && $payment) {
                foreach ([$debt, $payment] as $legs) {
                    foreach ($legs as $accountId => $operation) {
                        $net[$accountId] = ($net[$accountId] ?? 0) + ($operation === 'debit' ? 1 : -1);
                    }
                }
                $net = array_filter($net);
                if (count($net) === 2 && array_sum($net) === 0 && max($net) === 1 && min($net) === -1) {
                    $pair = array_map(fn ($sign) => $sign === 1 ? 'debit' : 'credit', $net);
                }
            }
        }
        if (! empty($data['debit_account_id']) && ! empty($data['credit_account_id'])) {
            // The journal below reverses a purchase pair, so invert explicit return legs here.
            $pair = [(int) $data['debit_account_id'] => 'credit', (int) $data['credit_account_id'] => 'debit'];
        }
        if (! $pair) {
            $this->fail('คู่บัญชีคืนสินค้าไม่ครบหรือไม่สมดุล กรุณาเลือกบัญชีเดบิตและเครดิตสำหรับใบคืนนี้ หรือตรวจกฎบัญชีจัดซื้อก่อนยืนยัน');
        }
        [$accountId, $offsetId] = array_keys($pair);
        $op = $pair[$accountId];
        $other = $pair[$offsetId];
        $h = new TransactionHistory;
        $h->name = 'คืนซัพพลายเออร์ RT-'.$r->id;
        $h->value = $r->total;
        $h->author_id = auth()->id();
        $h->transaction_account_id = $accountId;
        $h->operation = $op === 'debit' ? 'credit' : 'debit';
        $h->type = Transaction::TYPE_DIRECT;
        $h->trigger_date = now();
        $h->status = TransactionHistory::STATUS_ACTIVE;
        $h->procurement_id = $r->procurement_id;
        TransactionHistory::withoutEvents(function () use ($h, $offsetId, $other): void {
            $h->save();
            $mirror = $h->replicate();
            $mirror->transaction_account_id = $offsetId;
            $mirror->operation = $other === 'debit' ? 'credit' : 'debit';
            $mirror->is_reflection = true;
            $mirror->reflection_source_id = $h->id;
            $mirror->save();
        });
    }

    private function accountingPair(string $name): ?array
    {
        $rule = TransactionActionRule::where('on', $name)->first();
        $account = $rule ? TransactionAccount::find($rule->account_id) : null;
        $offset = $rule ? TransactionAccount::find($rule->offset_account_id) : null;
        $op = $account ? config('accounting.accounts.'.$account->category_identifier.'.'.$rule->action) : null;
        $other = $offset ? config('accounting.accounts.'.$offset->category_identifier.'.'.$rule->do) : null;
        if (! $account || ! $offset || $account->id === $offset->id || ! in_array($op, ['debit', 'credit'], true) || ! in_array($other, ['debit', 'credit'], true) || $op === $other) {
            return null;
        }

        return [$account->id => $op, $offset->id => $other];
    }

    public function cancelReturn(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $r = PurchaseReturn::lockForUpdate()->findOrFail($id);
            $this->authorize((int) $r->branch_id, 'nexopos.update.procurements');
            if ($r->status !== 'draft') {
                $this->fail('ยกเลิกได้เฉพาะฉบับร่างที่ยังไม่ส่งคืน');
            }
            $r->update(['status' => 'cancelled']);
        });
    }

    public function assertPaymentReady(Procurement $receipt): void
    {
        if (PurchaseReturn::where('procurement_id', $receipt->id)->where('mode', 'credit')->whereIn('status', ['draft', 'confirmed'])->exists()) {
            $this->fail('มีใบคืนแบบลดหนี้รอยืนยันผล กรุณาจัดการใบคืนก่อนชำระบิลนี้');
        }
    }
}
