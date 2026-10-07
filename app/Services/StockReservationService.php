<?php

namespace App\Services;

use App\Models\ProductUnitQuantity;
use App\Models\SalesDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockReservationService
{
    public function reserve(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $so = SalesDocument::lockForUpdate()->findOrFail($id);
            app(SalesWorkflowService::class)->authorize((int) $so->branch_id, 'nexopos.update.orders');
            abort_unless($so->status === 'confirmed' && ! $so->order_id, 422, 'จองได้เฉพาะใบสั่งขายที่ยังไม่ออกบิล');
            if (DB::table('nexopos_stock_reservations')->where('sales_document_id', $id)->where('status', 'active')->exists()) {
                return;
            }
            $amounts = [];
            foreach ($so->lines as $line) {
                $row = ProductUnitQuantity::with('product')->where('branch_id', $so->branch_id)->findOrFail($line['unit_quantity_id']);
                abort_unless((int) $row->product_id === (int) $line['product_id'] && (int) $row->unit_id === (int) $line['unit_id'], 422, 'หน่วยสินค้าเปลี่ยน กรุณาตรวจใบสั่งขาย');
                if ($row->product->stock_management !== 'enabled') {
                    continue;
                }
                $bearing = app(ProductService::class)->resolveStockBearingUnit($row->product_id, $row->unit_id, (int) $so->branch_id);
                $target = ProductUnitQuantity::where('branch_id', $so->branch_id)->where('product_id', $row->product_id)->where('unit_id', $bearing['unit_id'])->firstOrFail();
                $amounts[$target->id] = round(($amounts[$target->id] ?? 0) + $line['quantity'] * $bearing['factor'], 5);
            }
            abort_unless(count($amounts), 422, 'ใบนี้ไม่มีสินค้าที่จัดการสต๊อกให้จอง');
            ksort($amounts);
            foreach ($amounts as $rowId => $amount) {
                abort_unless($amount > 0, 422, 'จำนวนจองไม่ถูกต้อง');
                $changed = ProductUnitQuantity::whereKey($rowId)->whereRaw('quantity - reserved_quantity >= CAST(? AS DECIMAL(18,5))', [$amount])->increment('reserved_quantity', $amount);
                if (! $changed) {
                    throw ValidationException::withMessages(['reservation' => 'สต๊อกพร้อมขายไม่พอจองครบใบ กรุณาตรวจยอดจองและยอดคงเหลือ']);
                }
                DB::table('nexopos_stock_reservations')->insert(['sales_document_id' => $id, 'branch_id' => $so->branch_id, 'stock_row_id' => $rowId, 'quantity' => $amount, 'status' => 'active', 'author_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function release(int $id, string $status = 'released'): void
    {
        abort_unless(in_array($status, ['released', 'consumed']), 422);
        DB::transaction(function () use ($id, $status): void {
            $so = SalesDocument::lockForUpdate()->findOrFail($id);
            app(SalesWorkflowService::class)->authorize((int) $so->branch_id, 'nexopos.update.orders');
            $entries = DB::table('nexopos_stock_reservations')->where('sales_document_id', $id)->where('status', 'active')->orderBy('stock_row_id')->lockForUpdate()->get();
            if ($status === 'consumed' && $entries->isNotEmpty()) {
                $expected = [];
                foreach ($so->lines as $line) {
                    $row = ProductUnitQuantity::with('product')->where('branch_id', $so->branch_id)->findOrFail($line['unit_quantity_id']);
                    if ($row->product->stock_management !== 'enabled') {
                        continue;
                    }
                    $bearing = app(ProductService::class)->resolveStockBearingUnit($row->product_id, $row->unit_id, (int) $so->branch_id);
                    $target = ProductUnitQuantity::where('branch_id', $so->branch_id)->where('product_id', $row->product_id)->where('unit_id', $bearing['unit_id'])->firstOrFail();
                    $expected[$target->id] = round(($expected[$target->id] ?? 0) + $line['quantity'] * $bearing['factor'], 5);
                }
                $actual = $entries->mapWithKeys(fn ($entry) => [$entry->stock_row_id => round((float) $entry->quantity, 5)])->all();
                ksort($expected);
                ksort($actual);
                abort_unless($expected === $actual, 422, 'หน่วยหรือรายการสินค้าเปลี่ยนจากตอนจอง กรุณาปลดจองแล้วตรวจสอบและจองใหม่');
            }
            foreach ($entries as $entry) {
                $changed = ProductUnitQuantity::whereKey($entry->stock_row_id)->where('reserved_quantity', '>=', $entry->quantity)->decrement('reserved_quantity', $entry->quantity);
                abort_unless($changed, 422, 'ยอดจองไม่ตรง กรุณาติดต่อผู้ดูแลเพื่อตรวจสอบ');
                DB::table('nexopos_stock_reservations')->where('id', $entry->id)->update(['status' => $status, 'closed_by' => auth()->id(), 'closed_at' => now(), 'updated_at' => now()]);
            }
        });
    }
}
