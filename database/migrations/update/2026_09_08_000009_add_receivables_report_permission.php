<?php

/**
 * Table Migration
 *
 * Creates the receivables ageing permission on an installation that already exists.
 *
 * The permission is declared in database/permissions/reports.php, which the core
 * permission migration includes, but that migration only runs during setup. A shop that
 * was installed before this release would otherwise have the report's route and menu entry
 * with nothing granting access to them, which reads as "not enough permission" to every
 * user including the administrator.
 *
 * @since v6.3.0
 **/

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const PERMISSION = 'nexopos.reports.receivables';

    /**
     * Who is owed money is a management question, so the two administrative roles get it
     * and a cashier does not.
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
        if ( ! defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
            define( 'NEXO_CREATE_PERMISSIONS', true );
        }

        include_once dirname( __FILE__ ) . '/../../permissions/reports.php';

        foreach ( $this->administrativeRoles as $roleNamespace ) {
            $role = Role::namespace( $roleNamespace );

            if ( $role instanceof Role ) {
                $role->addPermissions( [ self::PERMISSION ] );
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
         * Nothing cascades from nexopos_permissions, so the role_permission rows have to be
         * cut first. Left behind they would point at a missing permission id, and a later
         * permission reusing that id would silently inherit the grant.
         */
        $permission = Permission::where( 'namespace', self::PERMISSION )->first();

        if ( $permission instanceof Permission ) {
            $permission->removeFromRoles();
            $permission->delete();
        }
    }
};
