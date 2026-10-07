<?php

namespace App\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DailyReconciliationService
{
    public function assertDeletable( Order $order ): void
    {
        if ( $order->payment_status !== Order::PAYMENT_HOLD || $order->payments()->exists() || $order->refunds()->exists() ) {
            throw new NotAllowedException( 'บิลที่มีธุรกรรมแล้วลบไม่ได้ กรุณายกเลิกบิลพร้อมระบุเหตุผล หรือคืนสินค้า เพื่อเก็บเลขบิลและประวัติรับเงินไว้ตรวจสอบ' );
        }
    }

    public function audit( Order $order, string $action ): void
    {
        $fields = ['code', 'branch_id', 'payment_status', 'total', 'tendered', 'change', 'voidance_reason'];
        if ( $action === 'updated' && ! $order->wasChanged( $fields ) ) {
            return;
        }
        DB::table( 'nexopos_order_audits' )->insert( [
            'order_id' => $order->id, 'order_code' => (string) $order->code,
            'branch_id' => $order->branch_id, 'actor_id' => Auth::id(), 'action' => $action,
            'before_data' => json_encode( array_intersect_key( $order->getRawOriginal(), array_flip( $fields ) ) ),
            'after_data' => json_encode( $order->only( $fields ) ), 'created_at' => now(),
        ] );
    }

    public function report( string $date, int $branchId ): array
    {
        $day = Carbon::parse( $date );
        $orders = Order::where( 'branch_id', $branchId )->whereBetween( 'created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()] )
            ->with( ['payments', 'refunds'] )->orderBy( 'code' )->get();
        $rows = [];
        $sales = 0.0;
        $outstanding = 0.0;
        foreach ( $orders as $order ) {
            $payments = (float) $order->payments->sum( 'value' );
            $refunds = (float) $order->refunds->sum( 'total' );
            $active = ! in_array( $order->payment_status, [Order::PAYMENT_HOLD, Order::PAYMENT_VOID, Order::PAYMENT_REFUNDED] );
            $total = $active ? (float) $order->total : 0.0;
            $due = $active ? max( 0, $total - max( 0, $payments - $refunds ) ) : 0;
            $flags = [];
            if ( abs( $payments - (float) $order->tendered ) > 0.01 ) {
                $flags[] = 'ยอดรายการรับเงินไม่ตรงกับยอดรับบนบิล';
            }
            if ( $due > 0 ) {
                $flags[] = 'ยังรับเงินไม่ครบ';
            }
            if ( $order->payment_status === Order::PAYMENT_PAID && $due > 0.01 ) {
                $flags[] = 'สถานะชำระครบแต่ยอดรับไม่ครบ';
            }
            if ( $refunds > 0 ) {
                $flags[] = 'มีรายการคืนเงิน — ตรวจหลักฐานคืนเงิน';
            }
            if ( $order->payment_status === Order::PAYMENT_VOID && $payments > $refunds ) {
                $flags[] = 'ยกเลิกบิลที่เคยรับเงิน — ตรวจการคืนเงิน';
            }
            $expectedChange = max( 0, $payments - $refunds - (float) $order->total );
            if ( $refunds > 0 && abs( max( 0, (float) $order->change ) - $expectedChange ) > 0.01 ) {
                $flags[] = 'ช่องเงินทอนเดิมไม่ตรงหลังคืนสินค้า ต้องตรวจใบเสร็จ';
            }
            $rows[] = compact( 'order', 'payments', 'refunds', 'total', 'due', 'flags' );
            $sales += $total;
            $outstanding += $due;
        }
        $audits = DB::table( 'nexopos_order_audits' )->where( 'branch_id', $branchId )
            ->where( function ( $query ) use ( $day, $orders ) {
                $query->whereBetween( 'created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()] )->orWhereIn( 'order_id', $orders->pluck( 'id' ) );
            } )->orderByDesc( 'id' )->limit( 1000 )->get();

        $prefix = $day->format( 'ymd' ) . '-';
        $sequences = DB::table( 'nexopos_orders' )->where( 'code', 'like', $prefix . '%' )->pluck( 'code' )
            ->filter( fn ( $code ) => preg_match( '/^' . preg_quote( $prefix, '/' ) . '[0-9]+$/', $code ) )
            ->map( fn ( $code ) => (int) substr( $code, strlen( $prefix ) ) )->unique()->sort()->values();
        $gaps = [];
        if ( $sequences->isNotEmpty() ) {
            $present = array_flip( $sequences->all() );
            for ( $i = max( 1, $sequences->first() ); $i < $sequences->last() && count( $gaps ) < 100; $i++ ) {
                if ( ! isset( $present[$i] ) ) {
                    $gaps[] = $prefix . str_pad( (string) $i, 3, '0', STR_PAD_LEFT );
                }
            }
        }

        return compact( 'rows', 'audits', 'sales', 'outstanding', 'gaps' );
    }
}
