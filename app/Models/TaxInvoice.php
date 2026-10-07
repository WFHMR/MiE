<?php

namespace App\Models;

use App\Casts\FloatConvertCasting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A Thai tax invoice issued against an order.
 *
 * @property int         $id
 * @property string      $uuid
 * @property string      $type
 * @property string      $sequence_scope
 * @property int         $sequence_number
 * @property string      $number
 * @property int         $order_id
 * @property Carbon      $issued_at
 * @property string      $seller_tax_id
 * @property string      $seller_name
 * @property string      $seller_address
 * @property string      $seller_branch_code
 * @property string|null $buyer_name
 * @property string|null $buyer_address
 * @property string|null $buyer_tax_id
 * @property string|null $buyer_code
 * @property string|null $buyer_phone
 * @property string|null $buyer_branch
 * @property string|null $seller_phone
 * @property string|null $payment_terms
 * @property Carbon|null $due_date
 * @property float       $vat_rate
 * @property float       $total_net
 * @property float       $total_vat
 * @property float       $total_gross
 * @property float       $total_exempt
 * @property float       $total_zero_rated
 * @property array       $lines
 * @property string      $status
 * @property string|null $cancellation_reason
 * @property Carbon|null $cancelled_at
 * @property int|null    $replaces_id
 * @property int|null    $author_id
 *
 * @method static Builder issued()
 * @method static Builder abbreviated()
 * @method static Builder full()
 */
class TaxInvoice extends NsModel
{
    use HasFactory;

    /**
     * ใบกำกับภาษีอย่างย่อ — Revenue Code Section 86/6. Issued at the counter, needs no
     * buyer details, prices are shown VAT inclusive.
     */
    const TYPE_ABBREVIATED = 'abbreviated';

    /**
     * ใบกำกับภาษีแบบเต็มรูป — Section 86/4. Required when the buyer will claim the VAT
     * as input tax, so it must carry the buyer's name, address and taxpayer number.
     */
    const TYPE_FULL = 'full';

    const STATUS_ISSUED = 'issued';

    const STATUS_CANCELLED = 'cancelled';

    protected $table = 'nexopos_' . 'tax_invoices';

    protected $casts = [
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'due_date' => 'date',
        'lines' => 'array',
        'vat_rate' => FloatConvertCasting::class,
        'total_net' => FloatConvertCasting::class,
        'total_vat' => FloatConvertCasting::class,
        'total_gross' => FloatConvertCasting::class,
        'total_exempt' => FloatConvertCasting::class,
        'total_zero_rated' => FloatConvertCasting::class,
    ];

    public function scopeIssued( Builder $query ): Builder
    {
        return $query->where( 'status', self::STATUS_ISSUED );
    }

    public function scopeAbbreviated( Builder $query ): Builder
    {
        return $query->where( 'type', self::TYPE_ABBREVIATED );
    }

    public function scopeFull( Builder $query ): Builder
    {
        return $query->where( 'type', self::TYPE_FULL );
    }

    public function order()
    {
        return $this->belongsTo( Order::class, 'order_id', 'id' );
    }

    public function user()
    {
        return $this->belongsTo( User::class, 'author_id' );
    }

    /**
     * The document this one supersedes, when it was issued as a replacement.
     */
    public function replaces()
    {
        return $this->belongsTo( self::class, 'replaces_id', 'id' );
    }

    /**
     * The printed Thai title for this document type.
     */
    public function getDocumentTitleAttribute(): string
    {
        return $this->type === self::TYPE_FULL
            ? __( 'ใบกำกับภาษี/ใบเสร็จรับเงิน' )
            : __( 'ใบกำกับภาษีอย่างย่อ' );
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
