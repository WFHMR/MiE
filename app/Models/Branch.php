<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int    $id
 * @property string $code
 * @property string $name
 * @property string $description
 * @property string $address
 * @property string $phone
 * @property string $email
 * @property bool   $is_active
 * @property bool   $is_main
 * @property int    $author_id
 * @property string $uuid
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Branch extends NsModel
{
    use HasFactory;

    protected $table = 'nexopos_branches';

    protected $fillable = [
        'code',
        'tax_branch_code',
        'name',
        'description',
        'address',
        'phone',
        'email',
        'is_active',
        'is_main',
        'is_main_warehouse',
        'author_id',
        'uuid',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_main' => 'boolean',
            'is_main_warehouse' => 'boolean',
        ];
    }

    /**
     * Relationships
     */
    public function registers()
    {
        return $this->hasMany( Register::class, 'branch_id', 'id' );
    }

    public function users()
    {
        return $this->hasMany( User::class, 'branch_id', 'id' );
    }

    public function orders()
    {
        return $this->hasMany( Order::class, 'branch_id', 'id' );
    }

    public function productHistories()
    {
        return $this->hasMany( ProductHistory::class, 'branch_id', 'id' );
    }

    public function productUnitQuantities()
    {
        return $this->hasMany( ProductUnitQuantity::class, 'branch_id', 'id' );
    }

    public function stockTransfersFrom()
    {
        return $this->hasMany( StockTransfer::class, 'from_branch_id', 'id' );
    }

    public function stockTransfersTo()
    {
        return $this->hasMany( StockTransfer::class, 'to_branch_id', 'id' );
    }

    public function author()
    {
        return $this->belongsTo( User::class, 'author_id' );
    }

    /**
     * Scopes
     */
    public function scopeActive( $query )
    {
        return $query->where( 'is_active', true );
    }

    public function scopeMain( $query )
    {
        return $query->where( 'is_main', true );
    }

    /**
     * The branches a given user is allowed to work in.
     *
     * A cashier belongs to one branch and may only see and select that one. The branch picker used
     * to render every branch to everybody with no check on the route behind it, so a cashier could
     * seat themselves in another branch and, until the till was made to outrank the picker, file
     * their sales there. With more branches the number of wrong places to land grows with each one.
     *
     * A user with no branch — the owner and the administrator — keeps the run of the company,
     * which is what makes the picker useful to them in the first place.
     */
    public function scopeSelectableBy( $query, ?User $user )
    {
        $query->where( 'is_active', true );

        if ( $user instanceof User && ! empty( $user->branch_id ) ) {
            $query->where( 'id', $user->branch_id );
        }

        return $query;
    }

    public function scopeMainWarehouse( $query )
    {
        return $query->where( 'is_main_warehouse', true );
    }

    public function scopeNotMainWarehouse( $query )
    {
        return $query->where( 'is_main_warehouse', false );
    }
}
