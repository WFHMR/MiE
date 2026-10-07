<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $uuid
 * @property string $name
 * @property string $description
 * @property int    $reward_system_id
 * @property float  $minimal_credit_payment
 * @property int    $payment_term_days
 * @property int    $author_id
 * @property Carbon $updated_at
 */
class CustomerGroup extends NsModel
{
    use HasFactory;

    protected $table = 'nexopos_' . 'customers_groups';

    protected function casts(): array
    {
        return [
            'payment_term_days' => 'integer',
        ];
    }

    /**
     * Whether the customers of this group are allowed to leave with the goods and
     * settle the invoice later. The number of days granted is the term itself, so a
     * single column carries both the permission and its length.
     */
    public function allowsCredit(): bool
    {
        return $this->payment_term_days > 0;
    }

    /**
     * define the relationship
     *
     * @return Model\RelationShip
     */
    public function customers()
    {
        return $this->hasMany( Customer::class, 'group_id' );
    }

    public function reward()
    {
        return $this->hasOne( RewardSystem::class, 'id', 'reward_system_id' );
    }
}
