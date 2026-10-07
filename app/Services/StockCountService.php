<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\StockCount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockCountService
{
    public function branches(): Builder
    {
        $query = Branch::active();
        if (! ns()->hasRole('admin')) {
            $query->where('id', auth()->user()->branch_id ?: -1);
        }

        return $query;
    }

    public function authorizeBranch(int $branch): void
    {
        abort_unless($this->branches()->whereKey($branch)->exists(), 403, 'ไม่มีสิทธิ์ตรวจนับสาขานี้');
    }

    public function access(StockCount $count): void
    {
        ns()->restrict('nexopos.stock-counts.work');
        $this->authorizeBranch((int) $count->branch_id);
    }

    public function row(int $id, int $branch): ProductUnitQuantity
    {
        $row = ProductUnitQuantity::where('branch_id', $branch)->lockForUpdate()->findOrFail($id);
        $product = Product::findOrFail($row->product_id);
        $unit = $row->unit;
        if ($product->stock_management !== Product::STOCK_MANAGEMENT_ENABLED || $product->type === Product::TYPE_GROUPED || ! $unit || ($product->stock_on_base_unit && ! $unit->base_unit)) {
            throw ValidationException::withMessages(['count' => 'ต้องเลือกสินค้าที่จัดการสต๊อก และนับเป็นหน่วยที่เก็บสต๊อกจริง (สินค้าหน่วยฐานให้รวมแพ็คเป็นชิ้นก่อน)']);
        }

        return $row;
    }

    public function history(ProductUnitQuantity $row): int
    {
        return (int) ProductHistory::where('product_id', $row->product_id)->where('unit_id', $row->unit_id)->where('branch_id', $row->branch_id)->max('id');
    }

    public function change(int $id, int $version, callable $callback): StockCount
    {
        return DB::transaction(function () use ($id, $version, $callback): StockCount {
            $count = StockCount::lockForUpdate()->findOrFail($id);
            $this->access($count);
            if ($count->status !== 'draft' || $count->version !== $version) {
                throw ValidationException::withMessages(['count' => 'เอกสารเปลี่ยนไปแล้วหรือยืนยันแล้ว กรุณาเปิดเอกสารใหม่ก่อนทำรายการ']);
            }
            $callback($count);
            $count->version++;
            $count->save();

            return $count;
        });
    }

    public function confirm(StockCount $count): void
    {
        ns()->restrict('nexopos.stock-counts.confirm');
        if (count($count->lines) === 0) {
            throw ValidationException::withMessages(['count' => 'ยังไม่มีรายการนับ']);
        }
        $lines = $count->lines;
        ksort($lines, SORT_NUMERIC);
        foreach ($lines as &$line) {
            $row = $this->row((int) $line['id'], (int) $count->branch_id);
            if ($line['counted'] === null) {
                throw ValidationException::withMessages(['count' => 'กรอกและบันทึกยอดนับให้ครบก่อนยืนยัน']);
            }
            if (abs((float) $row->quantity - $line['before']) > 0.00001 || $this->history($row) !== $line['history'] || (string) $row->updated_at !== $line['updated_at']) {
                throw ValidationException::withMessages(['count' => 'สต๊อกของ '.$line['name'].' เปลี่ยนระหว่างนับ ยังไม่ปรับยอดใด ๆ กรุณานำรายการนี้ออกแล้วเพิ่มเพื่อตรวจนับใหม่']);
            }
            if ($line['counted'] < (float) $row->reserved_quantity) {
                throw ValidationException::withMessages(['count' => 'ยอดนับต่ำกว่ายอดจอง กรุณาปลดจองหรือแก้ใบสั่งขายก่อนยืนยันนับ']);
            }
            $delta = $line['counted'] - $line['before'];
            if (abs($delta) > 0.00001) {
                $history = new ProductHistory;
                $history->product_id = $row->product_id;
                $history->unit_id = $row->unit_id;
                $history->branch_id = $row->branch_id;
                $history->operation_type = $delta > 0 ? ProductHistory::ACTION_ADDED : ProductHistory::ACTION_REMOVED;
                $history->before_quantity = $row->quantity;
                $history->quantity = abs($delta);
                $history->after_quantity = $line['counted'];
                $history->unit_price = $row->cogs;
                $history->total_price = round(abs($delta) * (float) $row->cogs, 5);
                $history->author_id = auth()->id();
                $history->description = 'ตรวจนับสต๊อก #'.$count->id.' — '.$count->title;
                $history->save();
                $row->quantity = $line['counted'];
                $row->save();
            }
        }
        unset($line);
        $count->status = 'confirmed';
        $count->confirmed_by = auth()->id();
        $count->confirmed_at = now();
    }
}
