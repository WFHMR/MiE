<?php

use App\Models\Permission;

if ( defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
    $permission = Permission::firstOrNew( [ 'namespace' => 'nexopos.read.audit-trail' ] );
    $permission->name = __( 'Read Audit Trail' );
    $permission->namespace = 'nexopos.read.audit-trail';
    $permission->description = __( 'Let the user read the record of who did what in the system. There is deliberately no permission to write or delete it.' );
    $permission->save();
}
