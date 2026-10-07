<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * The running number behind one series of tax invoices.
 *
 * @property int    $id
 * @property string $scope
 * @property int    $last_number
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TaxInvoiceSequence extends NsModel
{
    protected $table = 'nexopos_' . 'tax_invoice_sequences';

    protected $casts = [
        'last_number' => 'integer',
    ];
}
