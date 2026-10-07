<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * @property int         $id
 * @property int         $product_id
 * @property int         $unit_id
 * @property int         $from_branch_id
 * @property int         $to_branch_id
 * @property float       $quantity
 * @property string      $status         (pending, completed, cancelled)
 * @property int         $requested_by
 * @property int|null    $approved_by
 * @property string|null $notes
 * @property Carbon|null $approved_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 */
class StockTransfer extends Model
{
    public function save( array $options = [] )
    {
        if ( ! $this->exists ) {
            return parent::save( $options );
        }

        return $this->getConnection()->transaction( function () use ( $options ) {
            $this->assertPendingForMutation();

            return parent::save( $options );
        } );
    }

    public function delete()
    {
        if ( ! $this->exists ) {
            return parent::delete();
        }

        return $this->getConnection()->transaction( function () {
            $this->assertPendingForMutation();

            return parent::delete();
        } );
    }

    private function assertPendingForMutation(): void
    {
        // Hold the same row lock as approval until the edit or deletion commits.
        $current = $this->newQuery()->lockForUpdate()->findOrFail( $this->getKey() );

        if ( $current->status !== 'pending' ) {
            throw ValidationException::withMessages( [
                'status' => 'ใบโอนที่อนุมัติหรือยกเลิกแล้วไม่สามารถแก้ไขหรือลบได้',
            ] );
        }
    }

    protected $table = 'nexopos_stock_transfers';

    protected $fillable = [
        'product_id',
        'unit_id',
        'from_branch_id',
        'to_branch_id',
        'quantity',
        'status',
        'requested_by',
        'approved_by',
        'notes',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo( Product::class, 'product_id' );
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo( Unit::class, 'unit_id' );
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo( Branch::class, 'from_branch_id' );
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo( Branch::class, 'to_branch_id' );
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo( User::class, 'requested_by' );
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo( User::class, 'approved_by' );
    }

    public function scopePending( $query )
    {
        return $query->where( 'status', 'pending' );
    }

    public function scopeCompleted( $query )
    {
        return $query->where( 'status', 'completed' );
    }

    public function scopeCancelled( $query )
    {
        return $query->where( 'status', 'cancelled' );
    }
}
