<?php

namespace App\Models;

use App\Casts\FloatConvertCasting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A Thai withholding tax record and the certificate it prints.
 *
 * @property int         $id
 * @property string      $uuid
 * @property string      $sequence_scope
 * @property int         $sequence_number
 * @property string      $number
 * @property string      $form
 * @property string      $payee_type
 * @property string      $payee_name
 * @property string      $payee_tax_id
 * @property string      $payee_branch_code
 * @property string|null $payee_address
 * @property string      $income_type
 * @property string|null $income_description
 * @property float       $base_amount
 * @property float       $gross_amount
 * @property float       $rate
 * @property float       $tax_amount
 * @property Carbon      $paid_on
 * @property int|null    $transaction_id
 * @property string      $status
 * @property string|null $cancellation_reason
 * @property Carbon|null $cancelled_at
 * @property int|null    $author_id
 *
 * @method static Builder issued()
 * @method static Builder form( string $form )
 * @method static Builder paidBetween( string $start, string $end )
 */
class WithholdingTax extends NsModel
{
    use HasFactory;

    /**
     * ภ.ง.ด.3 — the monthly return for tax withheld from an individual.
     */
    const FORM_PND3 = 'pnd3';

    /**
     * ภ.ง.ด.53 — the monthly return for tax withheld from a juristic person.
     */
    const FORM_PND53 = 'pnd53';

    /**
     * บุคคลธรรมดา. Reported on ภ.ง.ด.3.
     */
    const PAYEE_INDIVIDUAL = 'individual';

    /**
     * นิติบุคคล — a company, partnership or other juristic person. Reported on ภ.ง.ด.53.
     */
    const PAYEE_JURISTIC = 'juristic';

    const STATUS_ISSUED = 'issued';

    const STATUS_CANCELLED = 'cancelled';

    protected $table = 'nexopos_' . 'withholding_taxes';

    protected $casts = [
        'paid_on' => 'date',
        'cancelled_at' => 'datetime',
        'base_amount' => FloatConvertCasting::class,
        'gross_amount' => FloatConvertCasting::class,
        'rate' => FloatConvertCasting::class,
        'tax_amount' => FloatConvertCasting::class,
    ];

    public function scopeIssued( Builder $query ): Builder
    {
        return $query->where( 'status', self::STATUS_ISSUED );
    }

    public function scopeForm( Builder $query, string $form ): Builder
    {
        return $query->where( 'form', $form );
    }

    /**
     * Scoped on paid_on, not created_at: the filing month is the month the money left,
     * and a payment recorded a week late still belongs to the month it was made.
     */
    public function scopePaidBetween( Builder $query, string $start, string $end ): Builder
    {
        return $query->whereDate( 'paid_on', '>=', $start )
            ->whereDate( 'paid_on', '<=', $end );
    }

    public function transaction()
    {
        return $this->belongsTo( Transaction::class, 'transaction_id', 'id' );
    }

    public function user()
    {
        return $this->belongsTo( User::class, 'author_id' );
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * The name of the monthly return this record is filed on.
     */
    public function getFormLabelAttribute(): string
    {
        return $this->form === self::FORM_PND3
            ? __( 'ภ.ง.ด.3' )
            : __( 'ภ.ง.ด.53' );
    }

    public function getPayeeTypeLabelAttribute(): string
    {
        return $this->payee_type === self::PAYEE_INDIVIDUAL
            ? __( 'บุคคลธรรมดา' )
            : __( 'นิติบุคคล' );
    }
}
