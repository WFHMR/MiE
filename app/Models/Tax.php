<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int    $id
 * @property string $uuid
 * @property string $description
 * @property float  $rate
 * @property int    $author_id
 * @property Carbon $updated_at
 */
class Tax extends NsModel
{
    use HasFactory;

    protected $table = 'nexopos_' . 'taxes';

    /**
     * The group this rate belongs to.
     *
     * Previously declared as belongsTo( Group::class ) with no return statement.
     * App\Models\Group does not exist — the model is TaxGroup — and without the
     * return the relation object was discarded, so $tax->group resolved to null
     * instead of raising. Both defects hid each other.
     */
    public function group()
    {
        return $this->belongsTo( TaxGroup::class, 'tax_group_id', 'id' );
    }

    public function user()
    {
        return $this->belongsTo( User::class, 'author_id' );
    }
}
