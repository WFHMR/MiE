<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductUnitQuantity;
use App\Models\SalesDelivery;
use App\Models\SalesDocument;
use App\Models\SalesQuotation;
use App\Models\TaxGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SalesWorkflowService
{
    public function authorize(int $branch, string $permission = 'nexopos.read.orders'): void
    {
        ns()->restrict($permission);
        abort_unless(Branch::active()->whereKey($branch)->exists() && (ns()->hasRole('admin') || (int) auth()->user()->branch_id === $branch), 403, 'ไม่มีสิทธิ์เข้าถึงสาขานี้');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['sales' => $message]);
    }

    public function saveQuote(array $input, ?int $id = null): SalesQuotation
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'branch_id' => 'required|integer', 'customer_id' => 'required|integer|exists:nexopos_users,id', 'name' => 'required|string|max:150', 'valid_until' => 'required|date_format:Y-m-d', 'terms' => 'nullable|string|max:3000', 'tax_group_id' => 'nullable|integer|exists:nexopos_taxes_groups,id', 'lines' => 'required|array|min:1|max:200', 'lines.*.unit_quantity_id' => 'required|integer', 'lines.*.quantity' => 'required|numeric|min:0.00001|max:999999|decimal:0,5', 'lines.*.unit_price' => 'required|numeric|min:0.01|max:9999999|decimal:0,2', 'version' => 'nullable|integer'])->validate();
        $this->authorize((int) $data['branch_id'], $id ? 'nexopos.update.orders' : 'nexopos.create.orders');

        return DB::transaction(function () use ($data, $id) {
            $q = $id ? SalesQuotation::lockForUpdate()->findOrFail($id) : SalesQuotation::where('request_key', $data['request_key'])->first();
            if ($q) {
                $this->authorize((int) $q->branch_id, 'nexopos.update.orders');
                if (! $id) {
                    abort_unless((int) $q->author_id === auth()->id(), 403);

                    return $q;
                }
                if ($q->status !== 'draft' || $q->version !== (int) ($data['version'] ?? 0)) {
                    $this->fail('แก้ไขได้เฉพาะฉบับร่างล่าสุด');
                }
                abort_unless((int) $q->branch_id === (int) $data['branch_id'], 422);
            }
            $group = ! empty($data['tax_group_id']) ? TaxGroup::with('taxes')->findOrFail($data['tax_group_id']) : null;
            $rate = $group ? (float) $group->taxes->sum('rate') : 0;
            $lines = [];
            $seen = [];
            foreach ($data['lines'] as $line) {
                $unit = ProductUnitQuantity::with(['product', 'unit'])->where('branch_id', $data['branch_id'])->findOrFail($line['unit_quantity_id']);
                if (! $unit->unit || ! $unit->product || $unit->product->type === 'grouped') {
                    $this->fail('กรุณาเลือกสินค้าและหน่วยขายที่ใช้งานได้');
                }
                if (isset($seen[$unit->id])) {
                    $this->fail('หน่วยสินค้าซ้ำ กรุณารวมจำนวน');
                }
                $seen[$unit->id] = true;
                $promotion = app(ScheduledPriceService::class)->active((int)$unit->product_id,(int)$unit->unit_id);
                if ($promotion) { $line['unit_price'] = (float)$promotion->price; }
                $total = round($line['quantity'] * $line['unit_price'], 2);
                $lines[] = ['product_id' => $unit->product_id, 'unit_id' => $unit->unit_id, 'unit_quantity_id' => $unit->id, 'name' => $unit->product->name, 'unit_name' => $unit->unit->name, 'quantity' => (float) $line['quantity'], 'unit_price' => (float) $line['unit_price'], 'total_price' => $total, 'tax_value' => round($total - $total / (1 + $rate / 100), 2), 'tax_type' => 'inclusive', 'tax_group_id' => $group?->id ?? 0];
            }
            $customer = Customer::findOrFail($data['customer_id']);
            $q ??= new SalesQuotation;
            $q->fill(['request_key' => $q->request_key ?? $data['request_key'], 'branch_id' => $data['branch_id'], 'customer_id' => $customer->id, 'customer_snapshot' => $customer->only(['first_name', 'last_name', 'phone', 'email']), 'name' => $data['name'], 'valid_until' => $data['valid_until'], 'terms' => $data['terms'] ?? null, 'lines' => $lines, 'total' => array_sum(array_column($lines, 'total_price')), 'tax' => array_sum(array_column($lines, 'tax_value')), 'pricing' => ['tax_group_id' => $group?->id, 'rate' => $rate, 'taxes' => $group ? $group->taxes->map(fn ($t) => ['tax_id' => $t->id, 'name' => $t->name, 'rate' => $t->rate])->all() : []], 'author_id' => $q->author_id ?? auth()->id(), 'status' => 'draft', 'version' => ($q->version ?? 0) + 1])->save();

            return $q;
        });
    }

    public function quoteAction(int $id, array $input): SalesQuotation
    {
        $data = Validator::make($input, ['action' => 'required|in:issue,cancel', 'version' => 'required|integer', 'acknowledge' => 'accepted'])->validate();

        return DB::transaction(function () use ($id, $data) {
            $q = SalesQuotation::lockForUpdate()->findOrFail($id);
            $this->authorize((int) $q->branch_id, 'nexopos.update.orders');
            if ($q->version !== (int) $data['version'] || ! in_array($q->status, ['draft', 'issued'])) {
                $this->fail('สถานะเอกสารเปลี่ยนแล้ว กรุณาเปิดใหม่');
            }
            if ($data['action'] === 'issue' && ($q->status !== 'draft' || $q->valid_until < now('Asia/Bangkok')->toDateString())) {
                $this->fail('ใบเสนอราคาต้องเป็นฉบับร่างและยังไม่หมดอายุ');
            }
            $q->update(['status' => $data['action'] === 'issue' ? 'issued' : 'cancelled', 'issued_at' => $data['action'] === 'issue' ? now() : $q->issued_at, 'version' => $q->version + 1]);

            return $q;
        });
    }

    public function accept(int $id, array $input): SalesDocument
    {
        $data = Validator::make($input, ['stock_timing' => 'required|in:invoice,dispatch', 'due_at' => 'required|date_format:Y-m-d', 'shipping_address' => 'required|string|max:2000', 'acceptance_note' => 'required|string|max:2000', 'acknowledge' => 'accepted'])->validate();

        return DB::transaction(function () use ($id, $data) {
            $q = SalesQuotation::lockForUpdate()->findOrFail($id);
            $this->authorize((int) $q->branch_id, 'nexopos.create.orders');
            ns()->restrict('nexopos.update.orders');
            if ($existing = SalesDocument::where('quotation_id', $id)->first()) {
                return $existing;
            }
            if ($q->status !== 'issued' || $q->valid_until < now('Asia/Bangkok')->toDateString()) {
                $this->fail('ต้องออกใบเสนอราคาที่ยังไม่หมดอายุก่อน');
            }
            $so = SalesDocument::create(['quotation_id' => $id, 'branch_id' => $q->branch_id, 'customer_id' => $q->customer_id, 'author_id' => auth()->id(), 'lines' => $q->lines, 'total' => $q->total, 'stock_timing' => $data['stock_timing'], 'due_at' => $data['due_at'], 'shipping_address' => $data['shipping_address'], 'status' => 'confirmed', 'version' => 1]);
            SalesDelivery::create(['sales_document_id' => $so->id, 'branch_id' => $so->branch_id, 'author_id' => auth()->id(), 'status' => 'preparing', 'version' => 1]);
            $q->update(['status' => 'accepted', 'accepted_at' => now(), 'accepted_by' => auth()->id(), 'acceptance_note' => $data['acceptance_note'], 'version' => $q->version + 1]);

            return $so;
        });
    }

    public function invoice(int $id, bool $dispatch = false): Order
    {
        return DB::transaction(function () use ($id, $dispatch) {
            $so = SalesDocument::lockForUpdate()->findOrFail($id);
            $this->authorize((int) $so->branch_id, 'nexopos.create.orders');
            ns()->restrict('nexopos.update.orders');
            if ($so->order_id) {
                $order = Order::findOrFail($so->order_id);
                if (in_array($order->payment_status, [Order::PAYMENT_VOID, Order::PAYMENT_REFUNDED, Order::PAYMENT_HOLD])) {
                    $this->fail('บิลขายถูกยกเลิก คืนครบ หรือพักบิล กรุณาตรวจเอกสารก่อนจัดส่ง');
                }
                if ((int) $order->branch_id !== (int) $so->branch_id || (int) $order->customer_id !== (int) $so->customer_id || abs((float) $order->total - (float) $so->total) > 0.01) {
                    $this->fail('สาขา ลูกค้า หรือยอดบิลเปลี่ยน กรุณาตรวจบิลขายก่อนจัดส่ง');
                }

                return $order;
            }
            if ($so->status !== 'confirmed') {
                $this->fail('ใบสั่งขายไม่พร้อมเปิดบิล');
            }
            if ($so->stock_timing === 'dispatch' && ! $dispatch) {
                $this->fail('ใบนี้เลือกตัดสต๊อกตอนส่ง ให้ยืนยันที่หน้าจัดส่ง');
            }
            $q = SalesQuotation::findOrFail($so->quotation_id);
            $pricing = $q->pricing;
            $fields = ['branch_id' => $so->branch_id, 'customer_id' => $so->customer_id, 'type' => ['identifier' => 'delivery'], 'payment_status' => 'unpaid', 'payments' => [], 'products' => $so->lines, 'tax_type' => 'inclusive', 'tax_value' => $q->tax, 'taxes' => $pricing['taxes'], 'discount' => 0, 'shipping' => 0, 'title' => 'SO-'.$so->id, 'note' => 'จากใบสั่งขาย SO-'.$so->id, 'addresses' => ['shipping' => ['address_1' => $so->shipping_address]], 'total' => (float) $so->total];
            if ($pricing['tax_group_id']) {
                $fields['tax_group_id'] = $pricing['tax_group_id'];
            }
            foreach ($so->lines as $index => $line) {
                $u = ProductUnitQuantity::where('branch_id', $so->branch_id)->find($line['unit_quantity_id']);
                if (! $u || (int) $u->product_id !== (int) $line['product_id'] || (int) $u->unit_id !== (int) $line['unit_id']) {
                    $this->fail('หน่วยสินค้าเปลี่ยน กรุณาตรวจใบเสนอราคาก่อนเปิดบิล');
                }
                $fields['products'][$index]['total_purchase_price'] = round((float) $u->cogs * $line['quantity'], 5);
            }
            // Existing checkout services use the selected branch for options as well as explicit branch_id.
            $previous = session('selected_branch_id');
            try {
                session(['selected_branch_id' => (int) $so->branch_id]);
                app(StockReservationService::class)->release($so->id, 'consumed');
                $result = app(OrdersService::class)->create($fields, null, true);
                $order = $result['data']['order'];
            } finally {
                session(['selected_branch_id' => $previous]);
            }
            if (abs((float) $order->total - (float) $so->total) > 0.01 || abs((float) $order->tax_value - (float) $q->tax) > 0.01) {
                $this->fail('ยอดหรือภาษีปัจจุบันไม่ตรงใบเสนอราคา กรุณาตรวจการตั้งค่าภาษีก่อนเปิดบิล');
            }
            app(OrdersService::class)->saveOrderProductHistory($order);
            app(TransactionService::class)->handleCogsFromSale($order);
            $so->update(['order_id' => $order->id, 'version' => $so->version + 1]);

            return $order;
        });
    }

    public function delivery(int $id, array $input): SalesDelivery
    {
        $data = Validator::make($input, ['action' => 'required|in:dispatch,delivered', 'acknowledge' => 'accepted', 'carrier' => 'required_if:action,dispatch|nullable|string|max:150', 'tracking' => 'nullable|string|max:150', 'note' => 'required|string|max:2000', 'received_by' => 'required_if:action,delivered|nullable|string|max:150'])->validate();

        return DB::transaction(function () use ($id, $data) {
            $reference = SalesDelivery::findOrFail($id);
            $this->authorize((int) $reference->branch_id, 'nexopos.update.orders');
            // Use the same lock order as invoice/cancellation.
            $so = SalesDocument::lockForUpdate()->findOrFail($reference->sales_document_id);
            $delivery = SalesDelivery::lockForUpdate()->findOrFail($id);
            if ($data['action'] === 'dispatch') {
                if (in_array($delivery->status, ['dispatched', 'delivered'])) {
                    return $delivery;
                }
                if ($so->status !== 'confirmed') {
                    $this->fail('ใบสั่งขายถูกยกเลิก');
                }
                if ($so->stock_timing === 'invoice' && ! $so->order_id) {
                    $this->fail('ใบนี้เลือกตัดสต๊อกเมื่อเปิดบิล ต้องเปิดบิลจากหน้าใบสั่งขายก่อน');
                }
                $order = $this->invoice($so->id, true);
                $expected = collect($so->lines)->mapWithKeys(fn ($l) => [$l['product_id'].':'.$l['unit_id'] => round($l['quantity'], 5)])->sortKeys()->all();
                $actual = $order->products()->get()->mapWithKeys(fn ($l) => [$l->product_id.':'.$l->unit_id => round($l->quantity, 5)])->sortKeys()->all();
                if ($expected !== $actual) {
                    $this->fail('รายการในบิลขายเปลี่ยน ไม่ตรงใบสั่งขาย กรุณาตรวจสอบก่อนส่ง');
                }
                $order->delivery_status = Order::DELIVERY_ONGOING;
                $order->save();
                $delivery->update(['status' => 'dispatched', 'carrier' => $data['carrier'], 'tracking' => $data['tracking'] ?? null, 'note' => $data['note'], 'dispatched_at' => now(), 'dispatched_by' => auth()->id(), 'version' => $delivery->version + 1]);
            } else {
                if ($delivery->status === 'delivered') {
                    return $delivery;
                }
                if ($delivery->status !== 'dispatched') {
                    $this->fail('ต้องยืนยันส่งก่อนรับมอบ');
                }
                $order = $this->invoice($so->id, true);
                $order->delivery_status = Order::DELIVERY_DELIVERED;
                $order->save();
                $delivery->update(['status' => 'delivered', 'note' => $delivery->note."\nรับมอบ: ".$data['note'], 'received_by' => $data['received_by'], 'delivered_at' => now(), 'delivered_by' => auth()->id(), 'version' => $delivery->version + 1]);
                $so->update(['status' => 'delivered', 'version' => $so->version + 1]);
            }

            return $delivery;
        });
    }

    public function cancel(int $id): void
    {
        DB::transaction(function () use ($id) {
            $so = SalesDocument::lockForUpdate()->findOrFail($id);
            $this->authorize((int) $so->branch_id, 'nexopos.update.orders');
            if ($so->order_id) {
                $this->fail('มีบิลขายแล้ว ต้องใช้ขั้นตอนยกเลิกบิล/คืนสินค้าเดิมก่อน ไม่สามารถยกเลิกใบสั่งขายทับได้');
            }
            if ($so->status !== 'confirmed') {
                $this->fail('เอกสารไม่ได้รอดำเนินการ');
            }
            app(StockReservationService::class)->release($so->id);
            $so->update(['status' => 'cancelled', 'version' => $so->version + 1]);
            SalesDelivery::where('sales_document_id', $id)->update(['status' => 'cancelled']);
        });
    }
}
