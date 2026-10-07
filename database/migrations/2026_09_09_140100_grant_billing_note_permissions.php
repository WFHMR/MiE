<?php

/**
 * Table Migration
 *
 * Creates the billing note permissions and grants them to the administrative roles.
 *
 * The permissions are declared in database/permissions/billing-notes.php and the core
 * permission migration includes that file, but that migration only runs once, during
 * setup. An installation set up before this release therefore has the routes without the
 * permissions that guard them, which reads as "not enough permission" to every user
 * including the administrator.
 *
 * Granting has to be explicit: Permission::includes( '.orders' ) in admin-role.php does
 * not match ".billing-notes", so the roles are told by name.
 *
 * Lives at the root of database/migrations rather than under update/ because the root is
 * what deploy/install-app.sh runs through "php artisan migrate --force". Under update/
 * it would only run when somebody walked through the browser update wizard, and a
 * deployed release would sit there with the feature installed and unusable.
 *
 * The hasTable guard makes the root safe: on a fresh install this runs before NexoPOS has
 * created its permission tables, finds nothing, and does nothing — the core migration
 * covers that case. Everything here is idempotent, so running it twice is harmless.
 *
 * @since v6.3.1
 **/

use App\Classes\Schema;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The roles that administer a store. A cashier is left out on purpose: a runner can be
     * granted issuing by hand, but cancelling a note releases every delivery on it to be
     * billed again, which belongs with a manager.
     *
     * @var array<int, string>
     */
    private array $administrativeRoles = [
        Role::ADMIN,
        Role::STOREADMIN,
    ];

    public function up(): void
    {
        if ( ! Schema::hasTable( 'nexopos_permissions' ) || ! Schema::hasTable( 'nexopos_roles' ) ) {
            return;
        }

        /**
         * The permission file is inert unless this is defined, which is what keeps it from
         * doing anything when it is pulled in by something other than a migration.
         */
        if ( ! defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
            define( 'NEXO_CREATE_PERMISSIONS', true );
        }

        include_once dirname( __FILE__ ) . '/../permissions/billing-notes.php';

        $namespaces = Permission::includes( '.billing-notes' )
            ->get()
            ->map( fn ( Permission $permission ) => $permission->namespace )
            ->unique()
            ->values()
            ->toArray();

        if ( empty( $namespaces ) ) {
            return;
        }

        foreach ( $this->administrativeRoles as $roleNamespace ) {
            $role = Role::namespace( $roleNamespace );

            if ( $role instanceof Role ) {
                $role->addPermissions( $namespaces );
            }
        }
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'nexopos_permissions' ) ) {
            return;
        }

        /**
         * Nothing cascades from nexopos_permissions, so the role_permission rows have to be
         * cut first. Left behind they point at a missing permission id, and a later
         * permission reusing that id would silently inherit the grant.
         */
        Permission::includes( '.billing-notes' )
            ->get()
            ->each( function ( Permission $permission ) {
                $permission->removeFromRoles();
                $permission->delete();
            } );
    }
};
