<?php

/**
 * Creates the audit trail permission and grants it to the owner.
 *
 * The permission is declared in database/permissions/audit-trail.php, which the core permission
 * migration includes — but that migration only runs during setup. An installation that already
 * exists would therefore have the page and the permission that guards it would not exist, so
 * nobody could hold it and the screen would refuse everybody including the owner.
 *
 * Granted to the owner (admin) only. Reading who did what is an owner's business, and a shop that
 * wants a manager to see it grants the permission in กำหนดสิทธิ์ — which is itself a change the
 * audit trail now records.
 *
 * Lives at the root of database/migrations because the root is what deploy/install-app.sh runs
 * through "php artisan migrate --force". The hasTable guard makes the root safe: on a fresh install
 * this runs before the permission tables exist, finds nothing and does nothing, and the core
 * migration covers that case. Everything here is idempotent.
 *
 * @since v6.3.2
 **/

use App\Classes\Schema;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const NAMESPACE = 'nexopos.read.audit-trail';

    public function up(): void
    {
        if ( ! Schema::hasTable( 'nexopos_permissions' ) || ! Schema::hasTable( 'nexopos_roles' ) ) {
            return;
        }

        $permission = Permission::firstOrNew( [ 'namespace' => self::NAMESPACE ] );
        $permission->name = __( 'Read Audit Trail' );
        $permission->namespace = self::NAMESPACE;
        $permission->description = __( 'Let the user read the record of who did what in the system. There is deliberately no permission to write or delete it.' );
        $permission->save();

        $role = Role::namespace( Role::ADMIN );

        if ( $role instanceof Role ) {
            $role->addPermissions( [ self::NAMESPACE ] );
        }
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'nexopos_permissions' ) ) {
            return;
        }

        /**
         * The pivot rows have to be cut first: left behind they point at a missing permission id,
         * and a later permission reusing that id would silently inherit the grant.
         */
        Permission::where( 'namespace', self::NAMESPACE )
            ->get()
            ->each( function ( Permission $permission ) {
                $permission->removeFromRoles();
                $permission->delete();
            } );
    }
};
