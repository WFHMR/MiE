<?php

namespace App\Models;

use App\Casts\FloatConvertCasting;
use App\Events\OrderTaxAfterCreatedEvent;
use App\Events\OrderTaxBeforeCreatedEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int    $id
 * @property int    $order_id
 * @property float  $tax_value
 * @property string $tax_name
 */
class OrderTax extends NsModel
{
    use HasFactory;

    protected $table = 'nexopos_' . 'orders_taxes';

    public $timestamps = false;

    public $casts = [
        'tax_value' => FloatConvertCasting::class,
        'rate' => FloatConvertCasting::class,
    ];

    public $dispatchesEvents = [
        'creating' => OrderTaxBeforeCreatedEvent::class,
        'created' => OrderTaxAfterCreatedEvent::class,
    ];

    /**
     * The order this tax line belongs to.
     *
     * The foreign key and owner key were inverted: order_id is the column on
     * nexopos_orders_taxes, and it points at nexopos_orders.id. The previous
     * declaration matched nexopos_orders.order_id, a column that does not exist.
     */
    public function order()
    {
        return $this->belongsTo( Order::class, 'order_id', 'id' );
    }
}
