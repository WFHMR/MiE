<?php

/**
 * RGBF Controller
 *
 * @since  1.0
 **/

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\DashboardController;
use App\Models\Permission;
use App\Models\PermissionAccess;
use Illuminate\Support\Facades\Auth;

class PermissionController extends DashboardController
{
    public function getSinglePermission( string $namespace )
    {
        return Permission::where( 'namespace', $namespace )->firstOrFail();
    }

    /**
     * Lists the permission accesses the logged user has granted.
     *
     * "status" and "granter_id" live on nexopos_permissions_access, not on
     * nexopos_permissions, so querying Permission raised "Unknown column
     * 'status'" and answered 500 on MySQL. SQLite hid it because it degrades an
     * unresolvable double quoted identifier into a string literal, which simply
     * made the condition false.
     */
    public function getAllGrandedPermissions()
    {
        return PermissionAccess::where( 'status', PermissionAccess::GRANTED )
            ->where( 'granter_id', Auth::id() )
            ->paginate( 10 );
    }

    public function checkPermissionAccess( string $namespace )
    {
        if ( ns()->allowedTo( $namespace ) ) {
            return response()->json( [
                'message' => 'Permission granted',
            ], 200 );
        }

        return response()->json( [
            'message' => 'Permission denied',
        ], 403 );
    }
}
