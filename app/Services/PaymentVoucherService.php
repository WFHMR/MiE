<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\PaymentVoucher;
use App\Models\Procurement;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\TransactionAccount;
use App\Models\TransactionActionRule;
use App\Models\TransactionHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentVoucherService
{
    public function authorize(int $branch): void
    {
        ns()->restrict(['nexopos.read.procurements', 'nexopos.update.procurements']);
        app(PurchaseOrderService::class)->authorize($branch);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['voucher' => $message]);
    }

    public function create(array $data): PaymentVoucher
    {
        $this->authorize((int) $data['branch_id']);

        return DB::transaction(function () use ($data): PaymentVoucher {
            $previous = PaymentVoucher::where('request_key', $data['request_key'])->first();
            if ($previous) {
                abort_unless((int) $previous->author_id === auth()->id() && (int) $previous->branch_id === (int) $data['branch_id'], 403);

                return $previous;
            }
            $ids = array_map('intval', $data['receipts']);
            sort($ids);
            if (count($ids) !== count(array_unique($ids))) {
                $this->fail('เลือกเอกสารซ้ำ');
            }
            $documents = Procurement::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $previous = PaymentVoucher::where('request_key', $data['request_key'])->first();
            if ($previous) {
                abort_unless((int) $previous->author_id === auth()->id() && (int) $previous->branch_id === (int) $data['branch_id'], 403);

                return $previous;
            }
            if (count($ids) !== $documents->count()) {
                $this->fail('ไม่พบเอกสารที่เลือก');
            }
            $lines = [];
            $total = 0;
            $expected = $data['mode'] === 'recorded' ? 'paid' : 'unpaid';
            foreach ($documents as $d) {
                app(PurchasingWorkflowService::class)->assertPaymentReady($d);
                if ((int) $d->branch_id !== (int) $data['branch_id'] || (int) $d->provider_id !== (int) $data['provider_id']) {
                    abort(403, 'เอกสารต้องเป็นสาขาและซัพพลายเออร์ที่เลือก');
                }
                if ($d->trial_archived_at || $d->delivery_status !== Procurement::STOCKED || $d->payment_status !== $expected || (float) $d->payable_total <= 0) {
                    $this->fail('เอกสารต้องรับเข้าแล้ว และมีสถานะชำระตรงกับประเภทที่เลือก');
                }
                if (DB::table('nexopos_payment_voucher_receipts')->where('procurement_id', $d->id)->exists()) {
                    $this->fail('เอกสารนี้มีใบสำคัญจ่ายยืนยันแล้ว');
                }
                $lines[] = ['id' => $d->id, 'name' => $d->name, 'purchase_order_number' => $d->purchase_order_number, 'invoice_reference' => $d->invoice_reference, 'invoice_date' => $d->invoice_date, 'delivery_note_number' => $d->delivery_note_number, 'billing_note_number' => $d->billing_note_number, 'cost' => (string) $d->payable_total, 'tax_value' => (string) $d->tax_value, 'payment_status' => $d->payment_status];
                $total += (float) $d->payable_total;
            }
            $provider = Provider::findOrFail($data['provider_id']);

            return PaymentVoucher::create(['request_key' => $data['request_key'], 'branch_id' => $data['branch_id'], 'provider_id' => $provider->id, 'author_id' => auth()->id(), 'mode' => $data['mode'], 'status' => 'draft', 'paid_at' => $data['paid_at'], 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'total' => round($total, 5), 'snapshot' => ['provider' => trim($provider->first_name.' '.$provider->last_name), 'provider_code' => $provider->code, 'branch' => Branch::findOrFail($data['branch_id'])->name, 'store' => ns()->option->get('ns_store_name'), 'address' => ns()->option->get('ns_store_address'), 'author' => auth()->user()->username, 'lines' => $lines]]);
        });
    }

    public function confirm(int $id): PaymentVoucher
    {
        $initial = PaymentVoucher::findOrFail($id);
        $this->authorize((int) $initial->branch_id);

        return DB::transaction(function () use ($id): PaymentVoucher {
            $v = PaymentVoucher::lockForUpdate()->findOrFail($id);
            $this->authorize((int) $v->branch_id);
            if ($v->status === 'confirmed') {
                return $v;
            }
            if ($v->status !== 'draft') {
                $this->fail('เอกสารนี้ไม่ใช่ฉบับร่าง');
            }
            Provider::whereKey($v->provider_id)->lockForUpdate()->firstOrFail();
            $snap = $v->snapshot;
            $documents = Procurement::whereIn('id', array_column($snap['lines'], 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($snap['lines'] as $line) {
                $d = $documents->get($line['id']);
                if ($d) {
                    app(PurchasingWorkflowService::class)->assertPaymentReady($d);
                }
                if (! $d || $d->trial_archived_at || $d->delivery_status !== Procurement::STOCKED || (int) $d->branch_id !== (int) $v->branch_id || (int) $d->provider_id !== (int) $v->provider_id || $d->payment_status !== $line['payment_status'] || number_format((float) $d->payable_total, 5, '.', '') !== number_format((float) $line['cost'], 5, '.', '')) {
                    $this->fail('ข้อมูลหรือยอดชำระเปลี่ยนไป กรุณายกเลิกฉบับร่างและสร้างใหม่');
                }
                foreach (['invoice_reference', 'invoice_date', 'billing_note_number', 'delivery_note_number', 'purchase_order_number'] as $field) {
                    if ((string) $d->$field !== (string) $line[$field]) {
                        $this->fail('ข้อมูลเอกสารอ้างอิงเปลี่ยนไป กรุณาสร้างฉบับร่างใหม่');
                    }
                }
                if (DB::table('nexopos_payment_voucher_receipts')->where('procurement_id', $d->id)->exists()) {
                    $this->fail('มีใบสำคัญจ่ายอื่นยืนยันบิลนี้แล้ว');
                }
            }
            foreach ($documents as $d) {
                app(PurchasingWorkflowService::class)->assertPaymentReady($d);
                if ($v->mode === 'payment') {
                    $this->postPayment($d, $v);
                    DB::table('nexopos_procurements')->where('id', $d->id)->update(['payment_status' => Procurement::PAYMENT_PAID, 'updated_at' => now()]);
                }
                DB::table('nexopos_payment_voucher_receipts')->insert(['voucher_id' => $v->id, 'procurement_id' => $d->id]);
            }
            if ($v->mode === 'payment') {
                app(ProviderService::class)->computeSummary(Provider::findOrFail($v->provider_id));
                app(ReportService::class)->computeDayReport();
            }
            $snap['confirmer'] = auth()->user()->username;
            $v->snapshot = $snap;
            $v->status = 'confirmed';
            $v->confirmed_by = auth()->id();
            $v->confirmed_at = now();
            $v->save();

            return $v;
        });
    }

    private function postPayment(Procurement $d, PaymentVoucher $v): void
    {
        $hasDebt = TransactionHistory::where('procurement_id', $d->id)->exists();
        $rule = TransactionActionRule::where('on', $hasDebt ? TransactionActionRule::RULE_PROCUREMENT_FROM_UNPAID_TO_PAID : TransactionActionRule::RULE_PROCUREMENT_PAID)->first();
        $account = $rule ? TransactionAccount::find($rule->account_id) : null;
        $offset = $rule ? TransactionAccount::find($rule->offset_account_id) : null;
        $operation = $account ? config('accounting.accounts.'.$account->category_identifier.'.'.$rule->action) : null;
        $otherOperation = $offset ? config('accounting.accounts.'.$offset->category_identifier.'.'.$rule->do) : null;
        if (! $account || ! $offset || $account->id === $offset->id || ! in_array($operation, ['debit', 'credit'], true) || ! in_array($otherOperation, ['debit', 'credit'], true) || $operation === $otherOperation) {
            $this->fail('กฎบัญชีการจ่ายซัพพลายเออร์ไม่ครบหรือไม่สมดุล กรุณาให้ผู้ดูแลตรวจตั้งค่าบัญชีก่อนยืนยัน');
        }
        $history = new TransactionHistory;
        $history->name = 'ใบสำคัญจ่าย '.$v->number.' / รับเข้า #'.$d->id;
        $history->value = $d->payable_total;
        $history->author_id = auth()->id();
        $history->transaction_account_id = $account->id;
        $history->operation = $operation;
        $history->type = Transaction::TYPE_DIRECT;
        $history->trigger_date = $v->paid_at.' 00:00:00';
        $history->status = TransactionHistory::STATUS_ACTIVE;
        $history->procurement_id = $d->id;
        $history->rule_id = $rule->id;
        TransactionHistory::withoutEvents(function () use ($history, $offset, $otherOperation): void {
            $history->save();
            $reflection = $history->replicate();
            $reflection->transaction_account_id = $offset->id;
            $reflection->operation = $otherOperation;
            $reflection->is_reflection = true;
            $reflection->reflection_source_id = $history->id;
            $reflection->rule_id = null;
            $reflection->save();
        });
        $reflection = TransactionHistory::where('reflection_source_id', $history->id)->first();
        if (! $reflection || (float) $reflection->value !== (float) $history->value || $reflection->operation === $history->operation) {
            $this->fail('บันทึกคู่บัญชีไม่สมบูรณ์ ยกเลิกการยืนยันครั้งนี้');
        }
    }

    public function cancel(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $v = PaymentVoucher::lockForUpdate()->findOrFail($id);
            $this->authorize((int) $v->branch_id);
            if ($v->status !== 'draft') {
                $this->fail('ยกเลิกได้เฉพาะฉบับร่าง');
            }$v->status = 'cancelled';
            $v->save();
        });
    }
}
