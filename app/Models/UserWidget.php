<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property mixed  $class_name
 * @property int    $user_id
 * @property Carbon $updated_at
 */
class UserWidget extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [ 'column', 'identifier', 'class_name', 'column', 'position', 'user_id' ];

    protected $table = 'nexopos_users_widgets';
}
