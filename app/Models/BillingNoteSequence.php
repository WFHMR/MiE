<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * The running number behind one series of billing notes.
 *
 * @property int    $id
 * @property string $scope
 * @property int    $last_number
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BillingNoteSequence extends NsModel
{
    protected $table = 'nexopos_' . 'billing_note_sequences';

    protected $casts = [
        'last_number' => 'integer',
    ];
}
