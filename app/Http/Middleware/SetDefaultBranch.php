<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetDefaultBranch
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle( Request $request, Closure $next ): Response
    {
        if ( ! auth()->check() ) {
            return $next( $request );
        }

        /**
         * A stale choice is corrected rather than kept. The key survives a reassignment or a branch
         * being switched off, and everything downstream — which stock is read, which shelf a
         * procurement lands on — follows it, so it is re-checked against what this user is allowed
         * on every request rather than only being filled when empty.
         */
        $selected = session( 'selected_branch_id' );

        if ( ! empty( $selected ) ) {
            $stillAllowed = Branch::selectableBy( auth()->user() )->where( 'id', $selected )->exists();

            if ( $stillAllowed ) {
                return $next( $request );
            }

            session()->forget( [ 'selected_branch_id', 'selected_branch_name' ] );
        }

        /**
         * The user's own branch when they have one. Only a user without a branch — the owner and
         * the administrator — falls through to the main branch, and then to the oldest, so an
         * install with nothing flagged main still seats them somewhere real.
         */
        $branch = Branch::selectableBy( auth()->user() )
            ->orderByDesc( 'is_main' )
            ->orderBy( 'id' )
            ->first();

        if ( $branch ) {
            session( [
                'selected_branch_id' => $branch->id,
                'selected_branch_name' => $branch->name,
            ] );
        }

        return $next( $request );
    }
}
