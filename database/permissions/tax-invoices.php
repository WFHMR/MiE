<?php

use App\Models\Permission;

if ( defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
    $permission = Permission::firstOrNew( [ 'namespace' => 'nexopos.read.tax-invoices' ] );
    $permission->name = __( 'Read Tax Invoices' );
    $permission->namespace = 'nexopos.read.tax-invoices';
    $permission->description = __( 'Let the user view and reprint Thai tax invoices.' );
    $permission->save();

    $permission = Permission::firstOrNew( [ 'namespace' => 'nexopos.create.tax-invoices' ] );
    $permission->name = __( 'Issue Tax Invoices' );
    $permission->namespace = 'nexopos.create.tax-invoices';
    $permission->description = __( 'Let the user issue a Thai tax invoice for a paid order.' );
    $permission->save();

    /**
     * Kept separate from issuing. Cancelling a tax invoice changes what the shop
     * reports on its PP30 return, so it is not something every cashier should be able
     * to do on their own.
     */
    $permission = Permission::firstOrNew( [ 'namespace' => 'nexopos.cancel.tax-invoices' ] );
    $permission->name = __( 'Cancel Tax Invoices' );
    $permission->namespace = 'nexopos.cancel.tax-invoices';
    $permission->description = __( 'Let the user cancel an issued Thai tax invoice.' );
    $permission->save();

    $permission = Permission::firstOrNew( [ 'namespace' => 'nexopos.reports.vat-return' ] );
    $permission->name = __( 'Read VAT Return Report' );
    $permission->namespace = 'nexopos.reports.vat-return';
    $permission->description = __( 'Let the user read the monthly Thai VAT return (PP30) report.' );
    $permission->save();

    /**
     * One permission for both delivery note registers. They are the same information split
     * by whether the money arrived, and a shop that trusts someone with one has no reason
     * to withhold the other.
     */
    $permission = Permission::firstOrNew( [ 'namespace' => 'nexopos.reports.delivery-notes' ] );
    $permission->name = __( 'Read Delivery Note Registers' );
    $permission->namespace = 'nexopos.reports.delivery-notes';
    $permission->description = __( 'Let the user read the daily registers of delivery notes issued, for cash bills and for consignments.' );
    $permission->save();
}
