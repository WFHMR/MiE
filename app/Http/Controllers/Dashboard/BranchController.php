<?php

namespace App\Http\Controllers\Dashboard;

use App\Crud\BranchCrud;
use App\Http\Controllers\DashboardController;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends DashboardController
{
    /**
     * The branch list, create and edit pages.
     *
     * BranchCrud::getLinks() already pointed at dashboard/branches, dashboard/branches/create
     * and dashboard/branches/edit/{id}, and its own row action links to the edit page, but no
     * route ever answered them. The menu meanwhile pointed at dashboard/crud/ns.branches, a
     * generic path this application does not serve — NexoPOS gives every resource its own
     * dashboard route — so the entry answered 404 for everyone including the administrator.
     */
    public function listBranches()
    {
        return BranchCrud::table();
    }

    public function createBranch()
    {
        return BranchCrud::form();
    }

    public function editBranch( Branch $branch )
    {
        return BranchCrud::form( $branch );
    }

    /**
     * Switch to a different branch
     */
    public function switchBranch( Request $request, int $id )
    {
        /**
         * Scoped rather than found outright. The route carries no permission middleware and the id
         * comes from the browser, so a cashier could previously seat themselves in any branch by
         * posting its number — which decided what stock they read and, until the till was made to
         * outrank the picker, where their sales were filed.
         */
        $branch = Branch::selectableBy( auth()->user() )->where( 'id', $id )->first();

        if ( ! $branch instanceof Branch ) {
            return response()->json( [
                'status' => 'error',
                'message' => __( 'คุณไม่มีสิทธิ์ทำงานในสาขานี้' ),
            ], 403 );
        }

        // Store selected branch in session
        session( [
            'selected_branch_id' => $branch->id,
            'selected_branch_name' => $branch->name,
        ] );

        return response()->json( [
            'status' => 'success',
            'message' => __( 'Switched to branch: :name', ['name' => $branch->name] ),
            'data' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ],
        ] );
    }

    /**
     * Get current selected branch
     */
    public function getCurrentBranch()
    {
        $branchId = session( 'selected_branch_id' );

        /**
         * The session is re-checked against what this user is allowed, not trusted outright: a
         * branch may have been deactivated, or the user reassigned, since it was written.
         */
        $branch = $branchId
            ? Branch::selectableBy( auth()->user() )->where( 'id', $branchId )->first()
            : null;

        if ( ! $branch instanceof Branch ) {
            $branch = Branch::selectableBy( auth()->user() )
                ->orderByDesc( 'is_main' )
                ->orderBy( 'id' )
                ->first();

            if ( $branch instanceof Branch ) {
                session( [
                    'selected_branch_id' => $branch->id,
                    'selected_branch_name' => $branch->name,
                ] );
            }
        }

        return response()->json( [
            'status' => 'success',
            'data' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ] : null,
        ] );
    }
}
