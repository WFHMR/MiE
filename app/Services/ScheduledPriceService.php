<?php

namespace App\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\TaxGroup;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ScheduledPriceService
{
    public function save(array $input): int
    {
        ns()->restrict('nexopos.manage.scheduled-prices');
        $d = Validator::make($input, ['request_key' => 'required|uuid', 'name' => 'required|string|max:150', 'unit_quantity_id' => 'required|integer', 'price' => 'required|numeric|min:0.01|max:9999999|decimal:0,2', 'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i|after:starts_at'])->validate();

        return DB::transaction(function () use ($d) {
            $u = ProductUnitQuantity::findOrFail($d['unit_quantity_id']);
            $p = Product::whereKey($u->product_id)->lockForUpdate()->firstOrFail();
            abort_if($p->type === 'grouped', 422, 'เลือกสินค้ารายตัวและหน่วยขาย');
            $old = DB::table('nexopos_scheduled_prices')->where('request_key', $d['request_key'])->first();
            if ($old) {
                return $old->id;
            }
            $start = Carbon::createFromFormat('Y-m-d\TH:i', $d['starts_at'], 'Asia/Bangkok')->second(0)->utc();
            $end = Carbon::createFromFormat('Y-m-d\TH:i', $d['ends_at'], 'Asia/Bangkok')->second(0)->utc();
            abort_if($end->lte(now()->utc()), 422, 'วันสิ้นสุดต้องอยู่ในอนาคต');
            abort_if(DB::table('nexopos_scheduled_prices')->where(['product_id' => $p->id, 'unit_id' => $u->unit_id, 'enabled' => true])->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists(), 422, 'มีราคาพิเศษของสินค้าและหน่วยนี้ทับซ้อนช่วงเวลา กรุณาหยุดโปรเดิมก่อน');

            return DB::table('nexopos_scheduled_prices')->insertGetId(['request_key' => $d['request_key'], 'name' => $d['name'], 'product_id' => $p->id, 'unit_id' => $u->unit_id, 'price' => $d['price'], 'starts_at' => $start, 'ends_at' => $end, 'author_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function stop(int $id, array $input): void
    {
        ns()->restrict('nexopos.manage.scheduled-prices');
        $d = Validator::make($input, ['reason' => 'required|string|min:5|max:2000'])->validate();
        DB::table('nexopos_scheduled_prices')->where('id', $id)->where('enabled', true)->update(['enabled' => false, 'stopped_by' => auth()->id(), 'stop_reason' => $d['reason'], 'stopped_at' => now(), 'updated_at' => now()]);
    }

    public function active(int $product, int $unit): ?object
    {
        $time = now()->utc();

        return DB::table('nexopos_scheduled_prices')->where(['product_id' => $product, 'unit_id' => $unit, 'enabled' => true])->where('starts_at', '<=', $time)->where('ends_at', '>', $time)->orderBy('id')->first();
    }

    public function display(ProductUnitQuantity $row): ProductUnitQuantity
    {
        $promo = $this->active((int) $row->product_id, (int) $row->unit_id);
        if (! $promo) {
            return $row;
        }
        $copy = clone $row;
        $product = $row->product;
        $gross = (float) $promo->price;
        $rate = $product && $product->tax_type !== 'disabled' ? (float) ($product->tax_group?->taxes?->sum('rate') ?? 0) : 0;
        $net = round($gross / (1 + $rate / 100), 5);
        foreach (['sale', 'wholesale', 'custom'] as $mode) {
            $copy->{$mode.'_price'} = $gross;
            $copy->{$mode.'_price_gross'} = $gross;
            $copy->{$mode.'_price_net'} = $net;
            $copy->{$mode.'_price_tax'} = $gross - $net;
            $copy->{$mode.'_price_edit'} = $product?->tax_type === 'exclusive' ? $net : $gross;
        }
        $copy->scheduled_price_id = $promo->id;
        $copy->scheduled_price_name = $promo->name;

        return $copy;
    }

    /** Validate before any payment, loyalty or stock mutation. Never silently reprice a submitted bill. */
    public function guard(array $fields): void
    {
        foreach ($fields['products'] ?? [] as $line) {
            $p = (int) ($line['product_id'] ?? 0);
            $u = (int) ($line['unit_id'] ?? 0);
            if (! $u && ! empty($line['unit_quantity_id'])) {
                $u = (int) ProductUnitQuantity::where('product_id', $p)->whereKey($line['unit_quantity_id'])->value('unit_id');
            }
            $promo = $this->active($p, $u);
            if (! $promo && empty($line['scheduled_price_id'])) {
                continue;
            }
            if (! $promo) {
                throw new NotAllowedException('ราคาพิเศษหมดอายุหรือถูกหยุด กรุณาลบสินค้านั้นแล้วเลือกใหม่เพื่อตรวจราคาก่อนชำระ');
            }
            $row = ProductUnitQuantity::where('product_id', $p)->where('unit_id', $u)->firstOrFail();
            $display = $this->display($row);
            $expected = ns()->option->get('ns_pos_vat') === 'disabled' ? $display->sale_price : (ns()->option->get('ns_pos_prefered_price') === 'gross_prices' ? $display->sale_price_gross : $display->sale_price_net);
            if (! empty($fields['tax_group_id'])) {
                $group = TaxGroup::with('taxes')->find($fields['tax_group_id']);
                $rate = (float) ($group?->taxes->sum('rate') ?? 0);
                $expected = ($fields['tax_type'] ?? '') === 'exclusive'
                    ? round((float) $promo->price / (1 + $rate / 100), 5)
                    : (float) $promo->price;
            }
            if (abs((float) ($line['unit_price'] ?? -1) - (float) $expected) > 0.011 || (! empty($line['scheduled_price_id']) && (int) $line['scheduled_price_id'] !== (int) $promo->id)) {
                throw new NotAllowedException('สินค้า '.$row->product->name.' มีราคาพิเศษใหม่ กรุณาลบแล้วเลือกสินค้าใหม่ก่อนชำระ');
            }
        }
    }
}
