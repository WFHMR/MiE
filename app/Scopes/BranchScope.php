<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * BranchScope - Global Query Scope for Branch Isolation
 *
 * Automatically filters all queries to only show records
 * belonging to the current user's branch
 */
class BranchScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply( Builder $builder, Model $model ): void
    {
        // Only apply if user is authenticated and has a branch_id
        if ( Auth::check() && Auth::user()->branch_id ) {
            $builder->where( $model->getTable() . '.branch_id', Auth::user()->branch_id );
        }
    }
}
