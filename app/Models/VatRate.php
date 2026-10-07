<?php

namespace App\Models;

use App\Casts\FloatConvertCasting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A VAT rate together with the period during which it is legally in force.
 *
 * @property int         $id
 * @property string      $uuid
 * @property string      $label
 * @property float       $rate
 * @property Carbon      $effective_from
 * @property Carbon|null $effective_to
 * @property string      $legal_reference
 * @property int         $author_id
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @method static Builder effectiveOn( Carbon|string|null $date )
 * @method static Builder openEnded()
 */
class VatRate extends NsModel
{
    use HasFactory;

    protected $table = 'nexopos_' . 'vat_rates';

    protected $casts = [
        'rate' => FloatConvertCasting::class,
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    /**
     * Restricts the query to the rates that are in force on the provided date.
     *
     * A null "effective_to" is an open ended period, which is the normal state of
     * the current rate: the Royal Decree that reduces Thai VAT to 7% is renewed
     * before it lapses, so the shop has no end date to enter until a decree
     * actually stops it.
     */
    public function scopeEffectiveOn( Builder $query, Carbon|string|null $date = null ): Builder
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse( $date ?: now() );

        return $query
            ->whereDate( 'effective_from', '<=', $date->toDateString() )
            ->where( function ( Builder $query ) use ( $date ) {
                $query->whereNull( 'effective_to' )
                    ->orWhereDate( 'effective_to', '>=', $date->toDateString() );
            } );
    }

    /**
     * Restricts the query to the periods that have no end date.
     */
    public function scopeOpenEnded( Builder $query ): Builder
    {
        return $query->whereNull( 'effective_to' );
    }

    public function user()
    {
        return $this->belongsTo( User::class, 'author_id' );
    }
}
