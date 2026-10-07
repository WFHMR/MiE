<?php

/**
 * Table Migration
 * Creates the permissions whose definition files were never wired into the installer, and gives them
 * to the administrator role.
 *
 * database/permissions holds one file per feature, and the installer includes them from
 * create_permissions.php. Six of them were written but never added to that list: branches, stores,
 * stock-transfers, drivers, registers-history and cash-flow-history. The permissions therefore did
 * not exist on a fresh installation, and the branches screen refused the administrator because the
 * permission it asks for could not be held by anyone. The administrator role was already asking for
 * three of those groups and quietly finding nothing.
 *
 * Every definition uses firstOrNew and addPermissions ignores duplicates, so this is safe to run on
 * an installation where the permissions were already created by hand.
 **/

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const FILES = [
        'branches',
        'stores',
        'stock-transfers',
        'drivers',
        'registers-history',
        'cash-flow-history',
    ];

    private const GROUPS = [
        '.branches',
        '.stores',
        '.stock-transfers',
        '.drivers',
        '.registers-history',
        '.cash-flow-history',
    ];

    public function up(): void
    {
        if ( ! defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
            define( 'NEXO_CREATE_PERMISSIONS', true );
        }

        foreach ( self::FILES as $file ) {
            $path = database_path( 'permissions/' . $file . '.php' );

            if ( is_readable( $path ) ) {
                include $path;
            }
        }

        $admin = Role::namespace( 'admin' );

        if ( ! $admin instanceof Role ) {
            return;
        }

        foreach ( self::GROUPS as $group ) {
            $namespaces = Permission::includes( $group )->get()->map( fn ( $permission ) => $permission->namespace );

            if ( $namespaces->isNotEmpty() ) {
                $admin->addPermissions( $namespaces );
            }
        }
    }

    /**
     * Deliberately empty. Removing a permission would strip it from every role that holds it, and
     * the screens that ask for it would start refusing everyone, which is worse than leaving a
     * permission in place.
     */
    public function down(): void
    {
        // Nothing to reverse.
    }
};
