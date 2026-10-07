<?php

namespace App\Models;

use App\Classes\Cache;
use App\Events\OrderPaymentAfterCreatedEvent;
use App\Events\OrderPaymentAfterUpdatedEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int    $id
 * @property int    $order_id
 * @property float  $value
 * @property int    $author_id
 * @property string $identifier
 * @property string $uuid
 */
class OrderPayment extends NsModel
{
    use HasFactory;

    protected $table = 'nexopos_' . 'orders_payments';

    const PAYMENT_CASH = 'cash-payment';

    const PAYMENT_ACCOUNT = 'account-payment';

    const PAYMENT_BANK = 'bank-payment';

    /**
     * PromptPay, the Thai national instant transfer scheme. Confirmed by hand at the
     * till: the cashier sees the transfer land in the shop's banking app and records it.
     * No QR code is generated here, so nothing about this identifier implies a gateway.
     *
     * @since v6.3.0
     */
    const PAYMENT_PROMPTPAY = 'promptpay-payment';

    /**
     * The Thai state welfare card (บัตรสวัสดิการแห่งรัฐ). The money does not reach the
     * shop at the till — it is settled later by the government — which is why these
     * payments have to be reconcilable one by one rather than as a daily total.
     *
     * @since v6.3.0
     */
    const PAYMENT_WELFARE_CARD = 'welfare-card-payment';

    public $dispatchesEvents = [
        'created' => OrderPaymentAfterCreatedEvent::class,
        'updated' => OrderPaymentAfterUpdatedEvent::class,
    ];

    public function order()
    {
        return $this->belongsTo( Order::class, 'order_id', 'id' );
    }

    public function scopeWithOrder( $query, $order_id )
    {
        return $query->where( 'order_id', $order_id );
    }

    public function type()
    {
        return $this->hasOne( PaymentType::class, 'identifier', 'identifier' );
    }

    public function getPaymentLabelAttribute()
    {
        $paymentTypes = Cache::remember( 'nexopos.pos.payments-key', '3600', function () {
            return PaymentType::active()->get()->mapWithKeys( function ( $paymentType ) {
                return [ $paymentType->identifier => $paymentType->label ];
            } );
        } );

        return $paymentTypes[ $this->identifier ] ?? __( 'Unknown Payment' );
    }
}
