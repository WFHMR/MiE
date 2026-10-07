<?php

/**
 * Table Migration
 *
 * Creates the Thai tax invoice permissions on an installation that already exists.
 *
 * The permissions themselves are declared in database/permissions/tax-invoices.php and
 * the core permission migration includes that file, but that migration only runs once,
 * during setup. An installation that was set up before this release therefore has the
 * routes and the menu entries without the permissions that guard them, which would read
 * as "not enough permission" to every user including the administrator.
 *
 * Granting is separate from creating because Permission::includes( '.taxes' ) in
 * admin-role.php does not match ".tax-invoices", so the roles have to be told explicitly.
 *
 * @since v6.3.0
 **/

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The roles that administer a store. A cashier is deliberately left out: issuing is
     * granted to whoever the shop decides, but cancelling an issued document changes a
     * filed return and belongs with a manager.
     *
     * @var array<int, string>
     */
    private array $administrativeRoles = [
        Role::ADMIN,
        Role::STOREADMIN,
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        /**
         * The permission file is a no-op unless this is defined, which is how it stays
         * inert when it gets pulled in by something other than a migration.
         */
        if ( ! defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
            define( 'NEXO_CREATE_PERMISSIONS', true );
        }

        include_once dirname( __FILE__ ) . '/../../permissions/tax-invoices.php';

        $namespaces = Permission::includes( '.tax-invoices' )
            ->get()
            ->map( fn( $permission ) => $permission->namespace )
            ->merge( Permission::includes( '.reports.vat-return' )->get()->map( fn( $permission ) => $permission->namespace ) )
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

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        /**
         * Nothing cascades from nexopos_permissions, so the role_permission rows have to
         * be cut first. Left behind they would point at a missing permission id, and a
         * later permission reusing that id would silently inherit the grant.
         */
        Permission::includes( '.tax-invoices' )
            ->get()
            ->merge( Permission::where( 'namespace', 'nexopos.reports.vat-return' )->get() )
            ->each( function ( Permission $permission ) {
                $permission->removeFromRoles();
                $permission->delete();
            } );
    }
};
