<?php

use App\Models\Permission;

if ( defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
    $permission = Permission::withNamespaceOrNew( 'nexopos.read.withholding-tax' );
    $permission->name = __( 'Read Withholding Tax' );
    $permission->description = __( 'Let the user view and reprint Thai withholding tax certificates.' );
    $permission->save();

    $permission = Permission::withNamespaceOrNew( 'nexopos.create.withholding-tax' );
    $permission->name = __( 'Issue Withholding Tax' );
    $permission->description = __( 'Let the user record a withholding and issue the certificate for it.' );
    $permission->save();

    $permission = Permission::withNamespaceOrNew( 'nexopos.update.withholding-tax' );
    $permission->name = __( 'Update Withholding Tax' );
    $permission->description = __( 'Let the user correct a withholding tax record before it is filed.' );
    $permission->save();

    /**
     * Kept separate from issuing. Cancelling changes what the shop reports on its monthly
     * return and therefore what it owes, so it is not something everyone who records a
     * payment should be able to do on their own.
     */
    $permission = Permission::withNamespaceOrNew( 'nexopos.delete.withholding-tax' );
    $permission->name = __( 'Cancel Withholding Tax' );
    $permission->description = __( 'Let the user cancel an issued Thai withholding tax certificate.' );
    $permission->save();

    $permission = Permission::withNamespaceOrNew( 'nexopos.reports.withholding-tax' );
    $permission->name = __( 'Read Withholding Tax Return Report' );
    $permission->description = __( 'Let the user read the monthly Thai withholding tax returns (PND3 and PND53).' );
    $permission->save();
}
