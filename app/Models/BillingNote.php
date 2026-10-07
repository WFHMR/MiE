<?php

namespace App\Models;

use App\Casts\FloatConvertCasting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * ใบแจ้งหนี้/วางบิลค่าสินค้า (ชั่วคราว) — a provisional billing note.
 *
 * Groups the delivery notes that have fallen due for one credit customer onto a single
 * sheet so their accounts desk can check them and schedule payment. It does not create
 * a liability; the tax invoices it lists already did.
 *
 * @property int         $id
 * @property string      $uuid
 * @property string      $sequence_scope
 * @property int         $sequence_number
 * @property string      $number
 * @property int|null    $customer_id
 * @property Carbon      $issued_at
 * @property Carbon|null $appointment_date
 * @property string      $seller_name
 * @property string|null $seller_address
 * @property string|null $seller_phone
 * @property string|null $seller_fax
 * @property string      $customer_name
 * @property string|null $customer_address
 * @property string|null $customer_code
 * @property array       $lines
 * @property float       $total_billed
 * @property float       $total_deducted
 * @property float       $total_net
 * @property string|null $notes
 * @property string      $status
 * @property string|null $cancellation_reason
 * @property Carbon|null $cancelled_at
 * @property int|null    $author_id
 *
 * @method static Builder issued()
 */
class BillingNote extends NsModel
{
    const STATUS_ISSUED = 'issued';

    const STATUS_CANCELLED = 'cancelled';

    protected $table = 'nexopos_' . 'billing_notes';

    protected $casts = [
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'appointment_date' => 'date',
        'lines' => 'array',
        'total_billed' => FloatConvertCasting::class,
        'total_deducted' => FloatConvertCasting::class,
        'total_net' => FloatConvertCasting::class,
    ];

    public function scopeIssued( Builder $query ): Builder
    {
        return $query->where( 'status', self::STATUS_ISSUED );
    }

    public function customer()
    {
        return $this->belongsTo( Customer::class, 'customer_id', 'id' );
    }

    public function user()
    {
        return $this->belongsTo( User::class, 'author_id' );
    }

    /**
     * The printed Thai title.
     */
    public function getDocumentTitleAttribute(): string
    {
        return __( 'ใบแจ้งหนี้/วางบิลค่าสินค้า (ชั่วคราว)' );
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
