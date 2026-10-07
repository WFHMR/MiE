<?php

namespace Modules\ProcurementImport\Models;

use App\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsvImportBatch extends Model
{
    protected $table = 'nexopos_csv_import_batches';

    protected $fillable = [
        'branch_id',
        'user_id',
        'filename',
        'status',
        'total_rows',
        'processed_rows',
        'success_count',
        'error_count',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Boot the model and apply BranchScope
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope());
        
        // Auto-set branch_id on creation
        static::creating(function ($model) {
            if (!$model->branch_id && auth()->check()) {
                $model->branch_id = auth()->user()->branch_id;
            }
        });
    }

    /**
     * Get all rows for this import batch
     */
    public function rows(): HasMany
    {
        return $this->hasMany(CsvImportRow::class, 'batch_id');
    }

    /**
     * Get progress percentage
     */
    public function getProgressAttribute(): float
    {
        if ($this->total_rows === 0) {
            return 0;
        }
        
        return round(($this->processed_rows / $this->total_rows) * 100, 2);
    }
}
