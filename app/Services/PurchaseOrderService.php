<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use App\Models\TransactionAccount;
use App\Models\TransactionActionRule;
use App\Models\TransactionHistory;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function branches(): Builder
    {
        $query = Branch::active();
        if ( ! ns()->hasRole( 'admin' ) ) {
            $query->where( 'id', auth()->user()->branch_id ?: -1 );
        }

        return $query;
    }

    public function authorize( int $branch ): void
    {
        abort_unless( $this->branches()->whereKey( $branch )->exists(), 403, 'ไม่มีสิทธิ์จัดซื้อหรือรับเข้าสาขานี้' );
    }

    public function normalize( array $products ): array
    {
        $lines = [];
        foreach ( $products as $input ) {
            $product = Product::findOrFail( $input['product_id'] );
            $unit = Unit::findOrFail( $input['unit_id'] );
            if ( $product->stock_management !== 'enabled' || $product->type === 'grouped' || (int) $product->unit_group !== (int) $unit->group_id ) {
                throw ValidationException::withMessages( ['products' => 'สินค้าและหน่วยไม่รองรับการรับสต๊อก'] );
            }
            $key = $product->id . ':' . $unit->id;
            if ( isset( $lines[$key] ) ) {
                throw ValidationException::withMessages( ['products' => 'สินค้าหน่วยเดียวกันซ้ำ กรุณารวมจำนวนในรายการเดียว'] );
            }
            $line = Arr::only( $input, ['product_id', 'unit_id', 'quantity', 'purchase_price', 'gross_purchase_price', 'net_purchase_price', 'tax_group_id', 'tax_type', 'tax_value', 'total_purchase_price'] );
            $line['name'] = $product->name;
            $line['unit_name'] = $unit->name;
            $line['received'] = 0;
            $lines[$key] = $line;
        }

        return array_values( $lines );
    }

    public function receive( int $id, array $data ): Procurement
    {
        ns()->restrict( ['nexopos.create.procurements', 'nexopos.update.procurements'] );

        if ( ! filter_var( $data['acknowledge'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
            throw ValidationException::withMessages( ['acknowledge' => 'กรุณายืนยันว่าตรวจนับสินค้าจริงครบแล้ว'] );
        }

        return DB::transaction( function () use ( $id, $data ): Procurement {
            $order = PurchaseOrder::lockForUpdate()->findOrFail( $id );
            $this->authorize( (int) $order->branch_id );
            if ( $order->status === 'received' || $order->version !== (int) $data['version'] ) {
                throw ValidationException::withMessages( ['receive' => 'เอกสารถูกเปลี่ยนหรือรับครบแล้ว กรุณาเปิดใหม่ก่อนรับ'] );
            }
            $lines = $order->lines;
            $products = [];
            foreach ( $data['quantities'] as $index => $quantity ) {
                if ( ! array_key_exists( $index, $lines ) ) {
                    throw ValidationException::withMessages( ['receive' => 'รายการรับไม่อยู่ในใบสั่งซื้อ'] );
                }
                $qty = (float) ( $quantity ?? 0 );
                $line = $lines[$index];
                if ( $qty <= 0 ) {
                    continue;
                }
                if ( $qty > round( $line['quantity'] - $line['received'], 5 ) ) {
                    throw ValidationException::withMessages( ['receive' => 'รับเกินจำนวนคงค้างของ ' . $line['name']] );
                }
                $this->normalize( [$line] );
                $bearing = app( ProductService::class )->resolveStockBearingUnit( product_id: $line['product_id'], unit_id: $line['unit_id'], branch_id: (int) $order->branch_id );
                if ( ! ProductUnitQuantity::where( 'branch_id', $order->branch_id )->where( 'product_id', $line['product_id'] )->where( 'unit_id', $bearing['unit_id'] )->exists() ) {
                    throw ValidationException::withMessages( ['receive' => 'ยังไม่มีหน่วยสต๊อกในสาขานี้ กรุณาตั้งค่าสินค้าก่อนรับ'] );
                }
                $fraction = $qty / (float) $line['quantity'];
                $products[] = array_merge( $line, ['quantity' => $qty, 'tax_value' => round( $line['tax_value'] * $fraction, 5 ), 'total_purchase_price' => round( $line['total_purchase_price'] * $fraction, 5 )] );
                $lines[$index]['received'] = round( $line['received'] + $qty, 5 );
            }
            if ( ! $products ) {
                throw ValidationException::withMessages( ['receive' => 'กรอกจำนวนรับอย่างน้อยหนึ่งรายการ'] );
            }
            $previousBranch = session( 'selected_branch_id' );
            session( ['selected_branch_id' => $order->branch_id] );
            try {
                $service = app( ProcurementService::class );
                $general = Arr::only( $data, ['invoice_reference', 'invoice_date', 'delivery_note_number', 'delivery_note_date', 'billing_note_number', 'billing_note_date', 'contact_person', 'credit_days', 'due_date', 'payment_status'] );
                $general = array_merge( $general, ['provider_id' => $order->provider_id, 'branch_id' => $order->branch_id, 'purchase_order_id' => $order->id, 'purchase_order_number' => $order->reference ?: 'PO-' . $order->id, 'delivery_time' => $data['received_at'], 'delivery_status' => 'pending', 'automatic_approval' => 0, 'billing_approval_status' => 'pending', 'payment_status' => 'unpaid'] );
                $result = $service->create( ['name' => 'รับสินค้า PO-' . $order->id . ' ครั้งที่ ' . ( count( $order->receipts ) + 1 ), 'general' => $general, 'products' => $products] );
                $receipt = $result['data']['procurement'];
                $order->lines = $lines;
                $receipts = $order->receipts;
                $receipts[] = ['id' => $receipt->id, 'at' => now()->toDateTimeString(), 'by' => auth()->id()];
                $order->receipts = $receipts;
                $order->status = collect( $lines )->every( fn ( $line ) => abs( $line['quantity'] - $line['received'] ) < 0.00001 ) ? 'received' : 'partial';
                $order->version++;
                $order->save();

                return $receipt;
            } finally {
                if ( $previousBranch === null ) {
                    session()->forget( 'selected_branch_id' );
                } else {
                    session( ['selected_branch_id' => $previousBranch] );
                }
            }
        } );
    }

    public function approveBilling( int $id ): Procurement
    {
        ns()->restrict( ['nexopos.read.procurements', 'nexopos.update.procurements'] );

        return DB::transaction( function () use ( $id ): Procurement {
            $receipt = Procurement::lockForUpdate()->findOrFail( $id );
            $this->authorize( (int) $receipt->branch_id );
            abort_if( $receipt->trial_archived_at || (! $receipt->purchase_order_id && ! in_array($receipt->receipt_source, ['invoice','no_bill'], true)), 422, 'เอกสารนี้ไม่อยู่ในขั้นตอนยืนยันวางบิล' );
            if ( $receipt->billing_approval_status === 'approved' ) {
                return $receipt;
            }
            abort_unless( $receipt->billing_approval_status === 'pending' && $receipt->delivery_status === 'pending' && $receipt->payment_status === 'unpaid', 422, 'เอกสารไม่อยู่ในสถานะรอยืนยัน' );
            if ( ! $receipt->invoice_date && $receipt->receipt_source !== 'no_bill' ) {
                throw ValidationException::withMessages( ['billing' => 'กรุณาบันทึกวันที่ใบกำกับภาษีก่อนยืนยัน'] );
            }
            Provider::whereKey( $receipt->provider_id )->lockForUpdate()->firstOrFail();
            if ( ! $receipt->due_date ) {
                $receipt->due_date = Carbon::parse( $receipt->invoice_date ?: $receipt->delivery_time )->addDays( (int) ( $receipt->credit_days ?? 0 ) )->format( 'Y-m-d' );
            }
            $service = app( ProcurementService::class );
            $stock = app( ProductService::class );
            $targets = [];
            foreach ( $receipt->products as $line ) {
                $bearing = $stock->resolveStockBearingUnit( product_id: $line->product_id, unit_id: $line->unit_id, branch_id: (int) $receipt->branch_id );
                $target = ProductUnitQuantity::where( 'branch_id', $receipt->branch_id )->where( 'product_id', $line->product_id )->where( 'unit_id', $bearing['unit_id'] )->first();
                if ( ! $target ) {
                    throw ValidationException::withMessages( ['receive' => 'ยังไม่มีหน่วยสต๊อกของ ' . $line->name . ' ในสาขานี้ กรุณาตั้งค่าสินค้าสาขาก่อนรับ'] );
                }
                $targets[] = ['row_id' => $target->id, 'line' => $line, 'factor' => $bearing['factor']];
            }
            usort( $targets, fn ( $a, $b ) => $a['row_id'] <=> $b['row_id'] );
            foreach ( $targets as $target ) {
                $row = ProductUnitQuantity::lockForUpdate()->findOrFail( $target['row_id'] );
                $line = $target['line'];
                $quantity = round( $line->quantity * $target['factor'], 5 );
                $before = (float) $row->quantity;
                $row->quantity = $before + $quantity;
                $row->save();
                $history = $stock->recordStockHistory( product_id: $row->product_id, action: ProductHistory::ACTION_STOCKED, unit_id: $row->unit_id, unit_price: $line->purchase_price / $target['factor'], quantity: $quantity, total_price: $line->total_purchase_price, author: auth()->id(), procurement_product_id: $line->id, procurement_id: $receipt->id, old_quantity: $before, new_quantity: $before + $quantity, branch_id: $receipt->branch_id );
                $service->generateBarcode( $line );
            }
            $service->setDeliveryStatus( $receipt, Procurement::STOCKED );
            app( ProviderService::class )->computeSummary( $receipt->provider );

            $receipt->billing_approval_status = 'approved';
            $receipt->billing_approved_by = auth()->id();
            $receipt->billing_approved_at = now();
            Procurement::withoutEvents( fn() => $receipt->save() );
            $this->postReceiptDebt( $receipt );
            app( ReportService::class )->computeDayReport();

            return $receipt;
        } );
    }

    private function postReceiptDebt( Procurement $d ): void
    {
        $rule = TransactionActionRule::where( 'on', TransactionActionRule::RULE_PROCUREMENT_UNPAID )->first();
        $account = $rule ? TransactionAccount::find( $rule->account_id ) : null;
        $offset = $rule ? TransactionAccount::find( $rule->offset_account_id ) : null;
        $operation = $account ? config( 'accounting.accounts.' . $account->category_identifier . '.' . $rule->action ) : null;
        $otherOperation = $offset ? config( 'accounting.accounts.' . $offset->category_identifier . '.' . $rule->do ) : null;
        if ( ! $account || ! $offset || $account->id === $offset->id || ! in_array( $operation, ['debit', 'credit'], true ) || ! in_array( $otherOperation, ['debit', 'credit'], true ) || $operation === $otherOperation ) {
            throw ValidationException::withMessages( ['billing' => 'กฎบัญชีรับเข้ายังไม่ครบหรือไม่สมดุล กรุณาตรวจตั้งค่าก่อนยืนยัน'] );
        }
        $history = new TransactionHistory;
        $history->name = 'ยืนยันวางบิล / รับเข้า #' . $d->id;
        $history->value = $d->cost;
        $history->author_id = auth()->id();
        $history->transaction_account_id = $account->id;
        $history->operation = $operation;
        $history->type = Transaction::TYPE_DIRECT;
        $history->trigger_date = now();
        $history->status = TransactionHistory::STATUS_ACTIVE;
        $history->procurement_id = $d->id;
        $history->rule_id = $rule->id;
        TransactionHistory::withoutEvents( function () use ( $history, $offset, $otherOperation ): void {
            $history->save();
            $reflection = $history->replicate();
            $reflection->transaction_account_id = $offset->id;
            $reflection->operation = $otherOperation;
            $reflection->is_reflection = true;
            $reflection->reflection_source_id = $history->id;
            $reflection->rule_id = null;
            $reflection->save();
        } );
        $reflection = TransactionHistory::where( 'reflection_source_id', $history->id )->first();
        if ( ! $reflection || (float) $reflection->value !== (float) $history->value || $reflection->operation === $history->operation ) {
            throw ValidationException::withMessages( ['billing' => 'บันทึกคู่บัญชีไม่สมบูรณ์ ยกเลิกการยืนยันครั้งนี้'] );
        }
    }
}
