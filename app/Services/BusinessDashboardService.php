<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderRefund;
use App\Models\PaymentVoucher;
use App\Models\Procurement;
use App\Models\ProductUnitQuantity;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\SalesDelivery;
use App\Models\SalesDocument;
use App\Models\SalesQuotation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BusinessDashboardService
{
    public const METRICS = [
        'sales' => ['label' => 'ยอดขายรวมภาษี', 'unit' => 'บาท', 'direction' => 'up', 'definition' => 'ยอดบิลในเดือน หักคืนตามสถานะล่าสุด ไม่รวมพักบิล ยกเลิก และคืนครบ'],
        'margin' => ['label' => 'อัตรากำไรขั้นต้น', 'unit' => '%', 'direction' => 'up', 'definition' => '(ยอดขายไม่รวมภาษีและค่าจัดส่ง − ต้นทุนสินค้าขาย) ÷ ยอดขายไม่รวมภาษีและค่าจัดส่ง × 100 ไม่ใช่กำไรสุทธิ'],
        'average' => ['label' => 'ยอดเฉลี่ยต่อบิล', 'unit' => 'บาท', 'direction' => 'up', 'definition' => 'ยอดขายรวมภาษี ÷ จำนวนบิลขายในเดือน'],
        'conversion' => ['label' => 'อัตราใบเสนอราคาเป็นใบสั่งขาย', 'unit' => '%', 'direction' => 'up', 'definition' => 'ใบเสนอราคาที่ออกในเดือนและมีใบสั่งขายไม่ยกเลิก ÷ ใบเสนอราคาที่ออกในเดือนทั้งหมด × 100 ตามสถานะปัจจุบัน'],
        'on_time' => ['label' => 'รับมอบครบตรงกำหนด', 'unit' => '%', 'direction' => 'up', 'definition' => 'ใบสั่งขายกำหนดส่งในเดือนถึงวันนี้ที่รับมอบครบไม่เกินวันกำหนด ÷ ใบสั่งขายครบกำหนดทั้งหมดที่ไม่ยกเลิก × 100 รวมค้างส่งในตัวหาร'],
        'purchase' => ['label' => 'งบรับสินค้าจัดซื้อ', 'unit' => 'บาท', 'direction' => 'down', 'definition' => 'มูลค่าเอกสารรับสินค้าที่เข้าสต๊อกในเดือน ไม่หักคืนต่างช่วงเวลา เป้าหมายคือเพดานงบ'],
        'stockout' => ['label' => 'สัดส่วนหน่วยสินค้าขาดสต๊อกปัจจุบัน', 'unit' => '%', 'direction' => 'down', 'definition' => 'หน่วยเก็บสต๊อกที่คงเหลือ ≤ 0 ÷ หน่วยเก็บสต๊อกทั้งหมด × 100 เป็นภาพปัจจุบัน ไม่ใช่ย้อนหลังตามเดือน'],
    ];

    private function period($q, string $column, string $from, string $to)
    {
        return $q->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);
    }

    public function sales(int $branch, string $from, string $to): array
    {
        $orders = $this->period(Order::with('products')->where('branch_id', $branch)->whereNotIn('payment_status', [Order::PAYMENT_VOID, Order::PAYMENT_HOLD, Order::PAYMENT_REFUNDED]), 'created_at', $from, $to)->get();
        $total = (float) $orders->sum('total');
        $tax = (float) $orders->sum('tax_value');
        $shipping = (float) $orders->sum('shipping');
        $cost = (float) $orders->sum(fn ($o) => $o->products->where('status', '!=', 'refunded')->sum('total_purchase_price'));
        $net = $total - $tax - $shipping;
        $profit = $net - $cost;
        $authors = User::whereIn('id', $orders->pluck('author_id'))->get()->keyBy('id');
        $team = $orders->groupBy('author_id')->map(fn ($rows, $id) => ['name' => $authors->get($id)?->username ?? 'ผู้ใช้ #'.$id, 'count' => $rows->count(), 'total' => (float) $rows->sum('total')])->sortByDesc('total');
        $days = $orders->groupBy(fn ($o) => substr((string) $o->created_at, 0, 10))->map(fn ($rows, $day) => ['day' => $day, 'count' => $rows->count(), 'total' => (float) $rows->sum('total')])->sortKeys();

        return compact('total', 'tax', 'shipping', 'cost', 'net', 'profit', 'team', 'days') + ['count' => $orders->count(), 'average' => $orders->count() ? $total / $orders->count() : null, 'margin' => $net > 0 ? $profit / $net * 100 : null];
    }

    public function purchase(int $branch, string $from, string $to): array
    {
        $receipts = $this->period(Procurement::with('provider')->where('branch_id', $branch)->whereNull('trial_archived_at')->where('delivery_status', 'stocked'), 'delivery_time', $from, $to)->get();
        $orders = $this->period(PurchaseOrder::where('branch_id', $branch), 'created_at', $from, $to)->get();
        $returns = $this->period(PurchaseReturn::where('branch_id', $branch)->whereIn('status', ['confirmed', 'settled']), 'confirmed_at', $from, $to)->get();
        $vendors = $receipts->groupBy('provider_id')->map(fn ($rows) => ['name' => trim(($rows->first()->provider?->first_name ?? '').' '.($rows->first()->provider?->last_name ?? '')), 'count' => $rows->count(), 'total' => (float) $rows->sum('cost')])->sortByDesc('total');

        return ['total' => (float) $receipts->sum('cost'), 'count' => $receipts->count(), 'vendors' => $vendors, 'statuses' => $orders->countBy('status'), 'returns' => (float) $returns->sum('total'), 'pending_returns' => $returns->where('status', 'confirmed')->count()];
    }

    public function inventory(int $branch): array
    {
        $rows = ProductUnitQuantity::with(['product', 'unit'])->where('branch_id', $branch)->whereHas('product', fn ($q) => $q->where('stock_management', 'enabled')->where('type', '!=', 'grouped'))->get();
        // Pack price rows do not add physical stock when the product pools on its base unit.
        $baseKeys = $rows->filter(fn ($row) => $row->unit?->base_unit && (float) $row->unit->value > 0)->mapWithKeys(fn ($row) => [$row->product_id.':'.$row->unit->group_id => true]);
        $rows = $rows->filter(function ($r) use ($baseKeys) {
            if (! $r->product || ! $r->unit) {
                return false;
            }
            if (! $r->product->stock_on_base_unit || $r->unit->base_unit) {
                return true;
            }

            return (float) $r->unit->value <= 0 || ! isset($baseKeys[$r->product_id.':'.$r->unit->group_id]);
        });
        $issues = $rows->filter(fn ($r) => $r->quantity <= 0 || $r->cogs <= 0 || ($r->stock_alert_enabled && $r->quantity <= $r->low_quantity));

        return ['value' => (float) $rows->sum(fn ($r) => $r->quantity * $r->cogs), 'count' => $rows->count(), 'out' => $rows->where('quantity', '<=', 0)->count(), 'negative' => $rows->where('quantity', '<', 0)->count(), 'missing_cost' => $rows->where('cogs', '<=', 0)->count(), 'low' => $rows->filter(fn ($r) => $r->stock_alert_enabled && $r->quantity <= $r->low_quantity)->count(), 'issues' => $issues->sortBy('quantity'), 'stockout' => $rows->count() ? $rows->where('quantity', '<=', 0)->count() / $rows->count() * 100 : null];
    }

    public function financial(int $branch, string $from, string $to): array
    {
        $sales = $this->sales($branch, $from, $to);
        // Cap all tenders chronologically at the original bill total to exclude change once.
        $ids = $this->period(OrderPayment::whereHas('order', fn ($q) => $q->where('branch_id', $branch)->where('payment_status', '!=', Order::PAYMENT_HOLD)), 'created_at', $from, $to)->pluck('order_id')->unique();
        $payments = OrderPayment::with('order')->whereIn('order_id', $ids)->orderBy('created_at')->orderBy('id')->get();
        $refundTotals = OrderRefund::whereIn('order_id', $ids)->select('order_id', DB::raw('SUM(total) AS amount'))->groupBy('order_id')->pluck('amount', 'order_id');
        $channels = [];
        $credit = 0;
        foreach ($payments->groupBy('order_id') as $id => $rows) {
            $remaining = max(0, (float) $rows->first()->order->total + (float) ($refundTotals[$id] ?? 0));
            foreach ($rows as $payment) {
                $amount = min(max(0, (float) $payment->value), $remaining);
                $remaining -= $amount;
                $day = substr((string) $payment->created_at, 0, 10);
                if ($day < $from || $day > $to) {
                    continue;
                }
                if ($payment->identifier === OrderPayment::PAYMENT_ACCOUNT) {
                    $credit += $amount;

                    continue;
                }
                $channels[$payment->identifier] = ($channels[$payment->identifier] ?? 0) + $amount;
            }
        }
        $refunds = (float) $this->period(OrderRefund::whereHas('order', fn ($q) => $q->where('branch_id', $branch))->where('payment_method', '!=', OrderPayment::PAYMENT_ACCOUNT), 'created_at', $from, $to)->sum('total');
        $vouchers = (float) $this->period(PaymentVoucher::where('branch_id', $branch)->where('status', 'confirmed')->where('mode', 'payment'), 'paid_at', $from, $to)->sum('total');
        $supplierRefunds = (float) $this->period(PurchaseReturn::where('branch_id', $branch)->where('mode', 'refund')->where('status', 'settled'), 'settled_at', $from, $to)->sum('total');
        $due = Order::withSum('payments', 'value')->withSum('refunds', 'total')->where('branch_id', $branch)->whereNotIn('payment_status', [Order::PAYMENT_VOID, Order::PAYMENT_HOLD, Order::PAYMENT_REFUNDED])->get()->sum(fn ($o) => max(0, (float) $o->total - max(0, (float) $o->payments_sum_value - (float) $o->refunds_sum_total)));

        return compact('sales', 'channels', 'credit', 'refunds', 'vouchers', 'supplierRefunds', 'due') + ['collections' => array_sum($channels), 'recorded_flow' => array_sum($channels) + $supplierRefunds - $refunds - $vouchers];
    }

    public function kpi(int $branch, string $month): array
    {
        $from = $month.'-01';
        $to = Carbon::parse($from)->endOfMonth()->toDateString();
        $sales = $this->sales($branch, $from, $to);
        $purchase = $this->purchase($branch, $from, $to);
        $inventory = $this->inventory($branch);
        $quotes = $this->period(SalesQuotation::where('branch_id', $branch)->whereNotNull('issued_at'), 'issued_at', $from, $to)->get();
        $converted = SalesDocument::whereIn('quotation_id', $quotes->pluck('id'))->where('status', '!=', 'cancelled')->count();
        $due = $this->period(SalesDocument::where('branch_id', $branch)->where('status', '!=', 'cancelled'), 'due_at', $from, min($to, now('Asia/Bangkok')->toDateString()))->get();
        $delivered = SalesDelivery::whereIn('sales_document_id', $due->pluck('id'))->where('status', 'delivered')->get()->keyBy('sales_document_id');
        $onTime = $due->filter(fn ($so) => isset($delivered[$so->id]) && substr((string) $delivered[$so->id]->delivered_at, 0, 10) <= $so->due_at)->count();
        $values = ['sales' => $sales['total'], 'margin' => $sales['margin'], 'average' => $sales['average'], 'conversion' => $quotes->count() ? $converted / $quotes->count() * 100 : null, 'on_time' => $due->count() ? $onTime / $due->count() * 100 : null, 'purchase' => $purchase['total'], 'stockout' => $inventory['stockout']];
        $targets = DB::table('nexopos_kpi_targets')->where('branch_id', $branch)->where('month', $from)->pluck('target', 'metric');

        return compact('values', 'targets') + ['conversion_count' => [$converted, $quotes->count()], 'delivery_count' => [$onTime, $due->count()]];
    }
}
