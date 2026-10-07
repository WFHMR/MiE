<?php

use App\Models\Permission;

if ( defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
    $permission = Permission::firstOrNew( [ 'namespace' => 'nexopos.read.billing-notes' ] );
    $permission->name = __( 'Read Billing Notes' );
    $permission->namespace = 'nexopos.read.billing-notes';
    $permission->description = __( 'Let the user view and reprint provisional billing notes.' );
    $permission->save();

    $permission = Permission::firstOrNew( [ 'namespace' => 'nexopos.create.billing-notes' ] );
    $permission->name = __( 'Issue Billing Notes' );
    $permission->namespace = 'nexopos.create.billing-notes';
    $permission->description = __( "Let the user raise a provisional billing note over a customer's outstanding deliveries." );
    $permission->save();

    /**
     * Kept separate from issuing. Cancelling releases every delivery on the note to be
     * billed again, so a customer could be asked twice for the same goods if it is done
     * carelessly — which makes it a supervisor's decision rather than a runner's.
     */
    $permission = Permission::firstOrNew( [ 'namespace' => 'nexopos.cancel.billing-notes' ] );
    $permission->name = __( 'Cancel Billing Notes' );
    $permission->namespace = 'nexopos.cancel.billing-notes';
    $permission->description = __( 'Let the user cancel an issued billing note.' );
    $permission->save();
}
