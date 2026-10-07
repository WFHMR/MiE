<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Services\ScheduledPriceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduledPriceController extends Controller
{
    public function index(Request $r)
    {
        ns()->restrict('read.dashboard');
        ns()->restrict('nexopos.read.scheduled-prices');
        $promos = DB::table('nexopos_scheduled_prices')->latest('id')->paginate(30);
        $products = Product::whereIn('id', $promos->pluck('product_id'))->get()->keyBy('id');
        $units = Unit::whereIn('id', $promos->pluck('unit_id'))->get()->keyBy('id');

        return view('pages.dashboard.promotions.scheduled', compact('promos', 'products', 'units') + ['title' => 'ราคาพิเศษตามช่วงเวลา']);
    }

    public function lookup(Request $r)
    {
        ns()->restrict('nexopos.manage.scheduled-prices');
        $r->validate(['q' => 'required|string|min:2|max:100']);
        $term = '%'.$r->input('q').'%';

        return ProductUnitQuantity::with(['product', 'unit'])->whereHas('product', fn ($q) => $q->where('type', '!=', 'grouped'))->where(fn ($q) => $q->where('barcode', 'like', $term)->orWhereHas('product', fn ($q) => $q->where('name', 'like', $term)->orWhere('sku', 'like', $term)))->limit(100)->get()->filter(fn ($u) => $u->unit && $u->product)->unique(fn ($u) => $u->product_id.':'.$u->unit_id)->take(30)->map(fn ($u) => ['id' => $u->id, 'label' => $u->product->name.' — '.$u->unit->name.' · '.$u->barcode])->values();
    }

    public function store(Request $r)
    {
        app(ScheduledPriceService::class)->save($r->all());

        return back()->with('success', 'บันทึกราคาพิเศษสำหรับสินค้าและหน่วยนี้ทุกสาขาแล้ว');
    }

    public function stop(Request $r, int $id)
    {
        app(ScheduledPriceService::class)->stop($id, $r->all());

        return back()->with('success', 'หยุดราคาพิเศษแล้ว เก็บประวัติเดิมไว้');
    }
}
