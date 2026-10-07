@php
    $receiptCustomer = $order->customer ?? null;
    $receiptSale = isset($order->id) ? \Illuminate\Support\Facades\DB::table('nexopos_loyalty_sales')->where('order_id', $order->id)->first() : null;
    $receiptEntry = $receiptSale ? \Illuminate\Support\Facades\DB::table('nexopos_loyalty_entries')->where('account_id', $receiptSale->account_id)->whereIn('reference', ['sale:'.$order->id.':earn', 'sale:'.$order->id.':redeem'])->orderByDesc('id')->first() : null;
@endphp
@if($receiptCustomer)
<div style="font-size:13px;color:#000;margin-bottom:8px;overflow-wrap:anywhere;">
    <div>ลูกค้า/สมาชิก: {{ trim($receiptCustomer->first_name.' '.$receiptCustomer->last_name) }}</div>
    @if($receiptSale)
    <div>รหัสสมาชิก: {{ $order->customer_id }}</div>
    @if($receiptSale->settled)
    <div>คะแนนที่ได้จากบิลนี้: {{ number_format($receiptSale->earned_points) }}</div>
    <div>คะแนนที่ใช้ในบิลนี้: {{ number_format($receiptSale->redeemed_points) }}</div>
    @if($receiptEntry && $receiptEntry->shared_balance !== null)
    <div>คะแนนคงเหลือหลังรายการ: {{ number_format($receiptEntry->shared_balance) }}</div>
    @endif
    @else
    <div>คะแนนบิลนี้ยังไม่บันทึก (รอชำระครบ)</div>
    @endif
    @endif
    @if($receiptSale || ($receiptCustomer instanceof \App\Models\Customer && app(\App\Services\BranchLoyaltyService::class)->eligible($receiptCustomer)))
    @unless($receiptSale)
    <div>บิลนี้ไม่มีรายการสะสม/แลกคะแนนที่บันทึกไว้</div>
    @endunless
    <div>คะแนนคงเหลือปัจจุบันทุกสาขา: {{ number_format(app(\App\Services\BranchLoyaltyService::class)->balance((int)$order->customer_id)) }}</div>
    @endif
</div>
@endif
