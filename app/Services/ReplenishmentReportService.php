<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReplenishmentReportService
{
    public function build(int $branch, string $asOf, string $basis, int $cover, int $lead, int $buffer, bool $whole): array
    {
        $end = CarbonImmutable::parse($asOf)->startOfDay()->addDay();
        $starts = ['week' => $end->subDays(7), 'previous' => $end->subDays(14), '1' => $end->subMonthsNoOverflow(1), '3' => $end->subMonthsNoOverflow(3), '6' => $end->subMonthsNoOverflow(6)];
        $periods = [];
        foreach ($starts as $key => $start) {
            $stop = $key === 'previous' ? $starts['week'] : $end;
            $periods[$key] = ['from' => $start->toDateString(), 'to' => $stop->subDay()->toDateString(), 'days' => (int) $start->diffInDays($stop)];
        }
        $stock = ProductUnitQuantity::with(['product', 'unit'])->where('branch_id', $branch)->whereHas('product', fn ($q) => $q->where('stock_management', 'enabled')->where('type', '!=', 'grouped'))->get();
        $units = Unit::all()->keyBy('id');
        $bases = $units->where('base_unit', true)->keyBy('group_id');
        $stockKeys = $stock->keyBy(fn ($r) => $r->product_id.':'.$r->unit_id);
        $products = $stock->pluck('product', 'product_id');
        $resolve = static function (int $productId, int $unitId) use ($products, $units, $bases, $stockKeys): array {
            $unit = $units->get($unitId);
            $base = $unit ? $bases->get($unit->group_id) : null;
            if ($products->get($productId)?->stock_on_base_unit && $unit && ! $unit->base_unit && $base && $unit->value > 0 && $base->value > 0 && $stockKeys->has($productId.':'.$base->id)) {
                return [$productId.':'.$base->id, (float) $unit->value / (float) $base->value];
            }

            return [$productId.':'.$unitId, 1.0];
        };
        $rows = [];
        foreach ($stock as $item) {
            if (! $item->product || ! $item->unit) {
                continue;
            }
            [$key] = $resolve($item->product_id, $item->unit_id);
            if ($key !== $item->product_id.':'.$item->unit_id) {
                continue;
            }
            $rows[$key] = ['product_id' => $item->product_id, 'name' => $item->product->name, 'sku' => $item->product->sku, 'unit' => $item->unit->name, 'stock' => (float) $item->quantity, 'totals' => array_fill_keys(array_keys($periods), 0.0)];
        }
        $saleDay = 'DATE('.DB::connection()->getQueryGrammar()->wrap('report_order.created_at').')';
        $sales = OrderProduct::query()->join('nexopos_orders as report_order', 'report_order.id', '=', 'nexopos_orders_products.order_id')
            ->where('report_order.branch_id', $branch)
            ->whereNotIn('report_order.payment_status', [Order::PAYMENT_HOLD, Order::PAYMENT_VOID, Order::PAYMENT_REFUNDED])
            ->whereDate('report_order.created_at', '>=', $periods[6]['from'])->whereDate('report_order.created_at', '<=', $asOf)
            ->where('nexopos_orders_products.quantity', '>', 0)
            ->select('nexopos_orders_products.product_id', 'nexopos_orders_products.unit_id', DB::raw($saleDay.' as sale_day'), DB::raw('SUM(quantity) as sold'))
            ->groupBy('nexopos_orders_products.product_id', 'nexopos_orders_products.unit_id', DB::raw($saleDay))->get();
        $unmapped = 0;
        foreach ($sales as $sale) {
            [$key, $factor] = $resolve((int) $sale->product_id, (int) $sale->unit_id);
            if (! isset($rows[$key])) {
                $unmapped++;

                continue;
            }
            foreach ($periods as $period => $window) {
                if ($sale->sale_day >= $window['from'] && $sale->sale_day <= $window['to']) {
                    $rows[$key]['totals'][$period] += (float) $sale->sold * $factor;
                }
            }
        }
        foreach ($rows as &$row) {
            $row['average'] = $row['totals'][$basis] / $periods[$basis]['days'];
            $row['wow'] = $row['totals']['previous'] > 0 ? ($row['totals']['week'] - $row['totals']['previous']) / $row['totals']['previous'] * 100 : null;
            $row['coverage'] = $row['average'] > 0 ? max(0, $row['stock']) / $row['average'] : null;
            $need = max(0, $row['average'] * ($cover + $lead + $buffer) - max(0, $row['stock']));
            $row['suggested'] = $row['average'] > 0 ? ($whole ? ceil(round($need, 5)) : ceil(round($need, 8) * 100000) / 100000) : null;
        }
        unset($row);

        return ['rows' => collect($rows)->sortByDesc('suggested')->values(), 'periods' => $periods, 'unmapped' => $unmapped];
    }
}
