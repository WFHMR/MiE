<?php

namespace App\Models;

use App\Classes\Model;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * @property int    $id
 * @property string $uuid
 * @property string $description
 * @property int    $group_id
 * @property float  $value
 * @property bool   $base_unit
 * @property Carbon $updated_at
 */
class Unit extends NsModel
{
    use HasFactory;

    public static function generateIdentifier( string $name ): string
    {
        $slug = Str::limit( Str::slug( $name ), 180, '' );
        if ( $slug !== '' && ! static::where( 'identifier', $slug )->exists() ) {
            return $slug;
        }

        return ( $slug !== '' ? $slug : 'unit' ) . '-' . Str::uuid();
    }

    protected $table = 'nexopos_units';

    protected $casts = [
        'base_unit' => 'boolean',
    ];

    /**
     * Stops a unit being deleted while a product still prices or stocks something in it.
     *
     * This was named setDepedencies, missing the second "n". NsDependable::delete() looks for
     * setDependencies with method_exists, so the name never matched and the guard never ran:
     * deleting a unit silently left every products_unit_quantities row pointing at a unit id
     * that no longer existed, which is what makes a product show a blank unit and no price.
     *
     * The declaration itself was already correct, so fixing the name is all that was needed.
     * The related lookup walks products_unit_quantities.product_id to the product and names it,
     * so the refusal tells the user which product is holding the unit.
     */
    public function setDependencies()
    {
        return [
            ProductUnitQuantity::class => Model::dependant(
                local_name: 'name',
                local_index: 'id',
                foreign_index: 'unit_id',
                related: Model::related(
                    model: Product::class,
                    foreign_index: 'product_id',
                    local_name: 'name',
                )
            ),
        ];
    }

    protected $guarded = [];

    public function group()
    {
        return $this->belongsTo( UnitGroup::class, 'group_id' );
    }

    /**
     * retrieve a unit using a defined
     * identifier
     *
     * @param  Query  $query
     * @param  string $identifier
     * @return Query
     */
    public function scopeIdentifier( $query, $identifier )
    {
        return $query->where( 'identifier', $identifier );
    }

    public function user()
    {
        return $this->belongsTo( User::class, 'author_id' );
    }
}
