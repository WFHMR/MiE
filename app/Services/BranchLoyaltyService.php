<?php

namespace App\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BranchLoyaltyService
{
    public function eligible(?Customer $customer): bool
    {
        return $customer !== null && trim((string) $customer->phone) !== '' && $customer->email !== 'walkin@store.local';
    }

    private function ensureAccount(int $customer, int $branch): ?object
    {
        $member = Customer::find($customer);
        if (! $this->eligible($member)) {
            return null;
        }
        $this->lockCustomer($customer);
        $account = DB::table('nexopos_loyalty_accounts')->where('customer_id', $customer)->orderBy('id')->first();
        if (! $account) {
            $id = DB::table('nexopos_loyalty_accounts')->insertGetId(['branch_id' => $branch, 'customer_id' => $customer, 'points' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $account = DB::table('nexopos_loyalty_accounts')->where('id', $id)->first();
        }

        return $account;
    }

    public function settings(int $branch): ?object
    {
        return DB::table('nexopos_loyalty_settings')->where('branch_id', 0)->first();
    }

    /** All historical branch contributions belong to one customer balance. */
    public function balance(int $customer, bool $lock = false): int
    {
        $query = DB::table('nexopos_loyalty_accounts')->where('customer_id', $customer);

        return (int) ($lock ? $query->orderBy('id')->lockForUpdate()->get(['points'])->sum('points') : $query->sum('points'));
    }

    public function lockCustomer(int $customer): void
    {
        DB::table('nexopos_users')->where('id', $customer)->lockForUpdate()->firstOrFail();
    }

    public function amount(int $points, object $settings): int
    {
        if ($points < 1 || $points % $settings->redeem_points !== 0) {
            throw new NotAllowedException('กรอกคะแนนเป็นจำนวนเท่าของ '.$settings->redeem_points.' คะแนน');
        }

        return intdiv($points, $settings->redeem_points) * $settings->redeem_satang;
    }

    /** Runs inside the same transaction as order creation and inventory deduction. */
    public function checkout(array $fields, ?Order $order, callable $create): array
    {
        $branch = (int) session('selected_branch_id');
        $settings = $this->settings($branch);
        $points = $fields['loyalty_points'] ?? 0;
        if (! is_numeric($points) || (int) $points != $points || $points < 0 || $points > 100000000) {
            throw new NotAllowedException('จำนวนคะแนนไม่ถูกต้อง');
        }
        $points = (int) $points;
        $existing = $order ? DB::table('nexopos_loyalty_sales')->where('order_id', $order->id)->first() : null;
        if ($existing && $existing->settled) {
            throw new NotAllowedException('บิลนี้บันทึกคะแนนแล้ว กรุณาใช้การคืนสินค้าหรือยกเลิกบิลแทนการแก้รายการ');
        }
        if ($existing) {
            DB::table('nexopos_loyalty_sales')->where('order_id', $order->id)->delete();
        }
        $customer = (int) ($fields['customer_id'] ?? $fields['customer']['id'] ?? 0);
        $account = DB::table('nexopos_loyalty_accounts')->where('customer_id', $customer)->orderBy('id')->first();
        if (! $account && $settings?->enabled && $branch > 0) {
            $account = $this->ensureAccount($customer, $branch);
        }
        if ($account) {
            $this->lockCustomer($customer);
            $account = DB::table('nexopos_loyalty_accounts')->where('id', $account->id)->lockForUpdate()->firstOrFail();
        }
        $discount = 0;
        $key = null;
        if ($points > 0) {
            ns()->restrict('nexopos.pos.cart-discount');
            app(MemberImportService::class)->authorizeBranch($branch);
            if (! $settings?->enabled || ! $account || $this->balance($customer, true) < $points) {
                throw new NotAllowedException('คะแนนรวมทุกสาขาไม่พอ หรือยังไม่ได้เปิดใช้การแลกคะแนน');
            }
            $key = $fields['loyalty_request_key'] ?? '';
            if (! Str::isUuid($key)) {
                throw new NotAllowedException('กรุณากดแลกคะแนนใหม่');
            }
            if (DB::table('nexopos_loyalty_sales')->where('request_key', $key)->when($order, fn ($q) => $q->where('order_id', '<>', $order->id))->exists()) {
                throw new NotAllowedException('รายการแลกคะแนนนี้ถูกใช้แล้ว กรุณาตรวจประวัติบิลก่อนทำซ้ำ');
            }
            $discount = $this->amount($points, $settings);
            if (($fields['discount_type'] ?? '') !== 'flat' || (int) round(((float) ($fields['discount'] ?? 0)) * 100) !== $discount || ! empty($fields['coupons'])) {
                throw new NotAllowedException('ส่วนลดไม่ตรงกับคะแนน กรุณากดแลกใหม่ และไม่ใช้ร่วมกับส่วนลดท้ายบิลหรือคูปอง');
            }
            if (($fields['payment_status'] ?? '') === 'hold') {
                throw new NotAllowedException('กรุณายกเลิกการแลกคะแนนก่อนพักบิล คะแนนยังไม่ถูกหัก');
            }
        }
        $result = $create($fields, $order);
        $saved = $result['data']['order'];
        if ($points > 0 && ($saved->payment_status !== Order::PAYMENT_PAID || (int) $saved->branch_id !== $branch || (int) $saved->customer_id !== $customer)) {
            throw new NotAllowedException('แลกคะแนนได้เมื่อชำระครบและสาขากับสมาชิกตรงกันเท่านั้น ยังไม่บันทึกบิล');
        }
        if ($settings?->enabled && $account && (int) $saved->branch_id === $branch && (int) $saved->customer_id === $customer) {
            DB::table('nexopos_loyalty_sales')->updateOrInsert(['order_id' => $saved->id], ['account_id' => $account->id, 'request_key' => $key, 'earn_satang' => $existing?->earn_satang ?? $settings->earn_satang, 'redeemed_points' => $points, 'discount_satang' => $discount, 'created_at' => now(), 'updated_at' => now()]);
            $this->settle($saved);
        }

        return $result;
    }

    public function settle(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $sale = DB::table('nexopos_loyalty_sales')->where('order_id', $order->id)->lockForUpdate()->first();
            if (! $sale || $sale->settled || $order->payment_status !== Order::PAYMENT_PAID) {
                return;
            }
            $account = DB::table('nexopos_loyalty_accounts')->where('id', $sale->account_id)->firstOrFail();
            $this->lockCustomer((int) $account->customer_id);
            $account = DB::table('nexopos_loyalty_accounts')->where('id', $sale->account_id)->lockForUpdate()->firstOrFail();
            if ($sale->redeemed_points > 0 && $this->balance((int) $account->customer_id, true) < $sale->redeemed_points) {
                throw new NotAllowedException('คะแนนไม่พอ ยังไม่บันทึกการชำระเงิน');
            }
            $paid = max(0, (int) round(((float) $order->total - (float) $order->shipping) * 100));
            $earned = intdiv($paid, $sale->earn_satang);
            $this->entry($account, -(int) $sale->redeemed_points, 'sale:'.$order->id.':redeem', 'แลกส่วนลดบิล '.$order->code, (int) $order->branch_id);
            $this->entry($account, $earned, 'sale:'.$order->id.':earn', 'สะสมคะแนนบิล '.$order->code, (int) $order->branch_id);
            DB::table('nexopos_loyalty_sales')->where('order_id', $order->id)->update(['settled' => true, 'paid_satang' => $paid, 'earned_points' => $earned, 'updated_at' => now()]);
        });
    }

    public function entry(object $account, int $points, string $reference, string $description, ?int $branch = null): void
    {
        DB::transaction(function () use ($account, $points, $reference, $description, $branch): void {
            $this->lockCustomer((int) $account->customer_id);
            if ($points === 0 || DB::table('nexopos_loyalty_entries')->where('reference', $reference)->exists()) {
                return;
            }
            $stored = DB::table('nexopos_loyalty_accounts')->where('id', $account->id)->lockForUpdate()->firstOrFail();
            $balance = (int) $stored->points + $points;
            $shared = $this->balance((int) $account->customer_id, true) + $points;
            DB::table('nexopos_loyalty_accounts')->where('id', $account->id)->update(['points' => $balance, 'updated_at' => now()]);
            DB::table('nexopos_loyalty_entries')->insert(['account_id' => $account->id, 'branch_id' => $branch ?? $stored->branch_id, 'reference' => $reference, 'points' => $points, 'balance' => $balance, 'shared_balance' => $shared, 'description' => $description, 'author_id' => auth()->id(), 'created_at' => now()]);
        });
    }

    public function reverse(Order $order, bool $void = false): void
    {
        DB::transaction(function () use ($order, $void): void {
            $sale = DB::table('nexopos_loyalty_sales')->where('order_id', $order->id)->lockForUpdate()->first();
            if (! $sale?->settled) {
                return;
            }
            $account = DB::table('nexopos_loyalty_accounts')->where('id', $sale->account_id)->firstOrFail();
            $this->lockCustomer((int) $account->customer_id);
            $account = DB::table('nexopos_loyalty_accounts')->where('id', $sale->account_id)->lockForUpdate()->firstOrFail();
            $refunded = (int) round($order->refunds()->get()->sum(fn ($r) => (float) $r->total - (float) $r->shipping) * 100);
            $remaining = $void ? 0 : max(0, $sale->paid_satang - $refunded);
            $targetEarned = min($sale->earned_points, intdiv($remaining, $sale->earn_satang));
            $already = (int) DB::table('nexopos_loyalty_entries')->where('account_id', $account->id)->where('reference', 'like', 'sale:'.$order->id.':reverse:%')->sum('points');
            $delta = $targetEarned - $sale->earned_points - $already;
            $this->entry($account, $delta, 'sale:'.$order->id.':reverse:'.($void ? 'void' : $refunded), 'ปรับคะแนนจากการคืน/ยกเลิกบิล '.$order->code, (int) $order->branch_id);
            if ($void || $remaining === 0) {
                $this->entry($account, (int) $sale->redeemed_points, 'sale:'.$order->id.':restore', 'คืนคะแนนที่ใช้แลกส่วนลดบิล '.$order->code, (int) $order->branch_id);
            }
        });
    }
}
