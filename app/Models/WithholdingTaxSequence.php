<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * The running number behind one series of withholding tax certificates.
 *
 * @property int    $id
 * @property string $scope
 * @property int    $last_number
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WithholdingTaxSequence extends NsModel
{
    protected $table = 'nexopos_' . 'withholding_tax_sequences';

    protected $casts = [
        'last_number' => 'integer',
    ];
}
