<?php

/**
 * Table Migration
 *
 * Creates the delivery note register permission and grants it to the administrative roles.
 *
 * The permission is declared alongside the tax invoice ones in
 * database/permissions/tax-invoices.php, which the core permission migration includes —
 * but that migration only runs once, during setup. An installation set up before this
 * release therefore has the two report routes without the permission that guards them,
 * which reads as "not enough permission" to every user including the administrator.
 *
 * Lives at the root of database/migrations because the root is what
 * deploy/install-app.sh runs through "php artisan migrate --force". Under update/ it would
 * only run when somebody walked through the browser update wizard, and a deployed release
 * would sit there with the reports installed and unreachable.
 *
 * The hasTable guard makes the root safe: on a fresh install this runs before NexoPOS has
 * created its permission tables, finds nothing, and does nothing — the core migration
 * covers that case. Everything here is idempotent.
 *
 * @since v6.3.1
 **/

use App\Classes\Schema;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const NAMESPACE = 'nexopos.reports.delivery-notes';

    /**
     * The same roles a fresh install would grant this to. admin-role.php and
     * store-admin-role.php both take everything matching ".reports.", and no cashier role
     * takes any report permission at all, so listing a cashier here would make an upgraded
     * installation behave differently from a fresh one. A shop that wants its cashiers to
     * print the closing register grants it to them in Roles.
     *
     * @var array<int, string>
     */
    private array $roles = [
        Role::ADMIN,
        Role::STOREADMIN,
    ];

    public function up(): void
    {
        if ( ! Schema::hasTable( 'nexopos_permissions' ) || ! Schema::hasTable( 'nexopos_roles' ) ) {
            return;
        }

        $permission = Permission::firstOrNew( [ 'namespace' => self::NAMESPACE ] );
        $permission->name = __( 'Read Delivery Note Registers' );
        $permission->namespace = self::NAMESPACE;
        $permission->description = __( 'Let the user read the daily registers of delivery notes issued, for cash bills and for consignments.' );
        $permission->save();

        foreach ( $this->roles as $roleNamespace ) {
            $role = Role::namespace( $roleNamespace );

            if ( $role instanceof Role ) {
                $role->addPermissions( [ self::NAMESPACE ] );
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
        Permission::where( 'namespace', self::NAMESPACE )
            ->get()
            ->each( function ( Permission $permission ) {
                $permission->removeFromRoles();
                $permission->delete();
            } );
    }
};
