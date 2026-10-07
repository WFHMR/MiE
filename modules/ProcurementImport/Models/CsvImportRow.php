<?php

namespace Modules\ProcurementImport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsvImportRow extends Model
{
    protected $table = 'nexopos_csv_import_rows';

    protected $fillable = [
        'batch_id',
        'row_number',
        'status',
        'row_data',
        'error_message',
    ];

    protected $casts = [
        'row_data' => 'array',
    ];

    /**
     * Get the batch that owns this row
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CsvImportBatch::class, 'batch_id');
    }
}
