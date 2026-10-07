<?php

namespace Modules\ProcurementImport\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Branch Scope - แยกข้อมูลตามสาขาอัตโนมัติ
 * 
 * Apply to models:
 * - Product
 * - ProductCategory
 * - Provider
 * - UnitGroup
 * - Unit
 * - Order
 * - Procurement
 * - Customer
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Skip if model doesn't have branch_id
        if (!$model->isFillable('branch_id') && !in_array('branch_id', $model->getFillable())) {
            return;
        }
        
        // Get current user's branch
        $branchId = $this->getCurrentBranchId();
        
        if ($branchId) {
            $builder->where($model->getTable() . '.branch_id', $branchId);
        }
    }
    
    /**
     * Get current branch ID from user or session
     */
    protected function getCurrentBranchId(): ?int
    {
        // 1. Try from authenticated user
        if (auth()->check() && auth()->user()->branch_id) {
            return auth()->user()->branch_id;
        }
        
        // 2. Try from session (for CSV import selection)
        if (session()->has('current_branch_id')) {
            return session('current_branch_id');
        }
        
        // 3. Try from request (API calls)
        if (request()->has('branch_id')) {
            return request()->input('branch_id');
        }
        
        // 4. Default to main branch
        return 1;
    }
}
