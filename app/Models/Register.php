<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Auth;

/**
 * @property int    $id
 * @property mixed  $name
 * @property mixed  $status
 * @property string $description
 * @property int    $used_by
 * @property int    $author_id
 * @property float  $balance
 * @property mixed  $uuid
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Register extends NsModel
{
    use HasFactory;

    protected $table = 'nexopos_' . 'registers';

    const STATUS_OPENED = 'opened';

    const STATUS_CLOSED = 'closed';

    const STATUS_DISABLED = 'disabled';

    const STATUS_INUSE = 'in-use';

    public function scopeClosed( $query )
    {
        return $query->where( 'status', self::STATUS_CLOSED );
    }

    public function scopeOpened( $query )
    {
        return $query->where( 'status', self::STATUS_OPENED );
    }

    public function scopeInUse( $query )
    {
        return $query->where( 'status', self::STATUS_INUSE );
    }

    public function scopeDisabled( $query )
    {
        return $query->where( 'status', self::STATUS_DISABLED );
    }

    public function scopeUsedBy( $query, $user )
    {
        return $query->where( 'used_by', $user );
    }

    /**
     * Restricts tills to what the signed in user is allowed to reach.
     *
     * A user bound to one till sees only that till, which is how a branch runs several
     * checkout stations at once without a cashier opening the drawer of the next counter.
     * A user with a branch but no binding sees every till in their branch, which suits a
     * branch supervisor. A user with neither, which is how the system administrator is
     * stored, sees everything.
     *
     * Without this the Tha Chang cashier was shown the main branch till and got "Only
     * closed register can be opened", because that till was already held open by the
     * administrator.
     */
    public function scopeForCurrentBranch( $query )
    {
        $user = Auth::user();

        if ( $user === null ) {
            return $query;
        }

        if ( $user->assigned_register_id !== null ) {
            return $query->where( $this->getTable() . '.id', $user->assigned_register_id );
        }

        if ( $user->branch_id === null ) {
            return $query;
        }

        return $query->where( $this->getTable() . '.branch_id', $user->branch_id );
    }

    public function history()
    {
        return $this->hasMany( RegisterHistory::class, 'register_id', 'id' );
    }

    public function user()
    {
        return $this->belongsTo( User::class, 'author_id' );
    }

    public function cashier()
    {
        return $this->belongsTo( User::class, 'used_by' );
    }

    public function branch()
    {
        return $this->belongsTo( Branch::class, 'branch_id' );
    }
}
