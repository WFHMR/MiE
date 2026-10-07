<?php

use App\Models\Permission;
use App\Models\Role;
use App\Widgets\ProfileWidget;

$storeCashier = Role::firstOrNew( [ 'namespace' => 'nexopos.store.cashier' ] );
$storeCashier->name = __( 'Store Cashier' );
$storeCashier->namespace = 'nexopos.store.cashier';
$storeCashier->locked = true;
/** Thai literal rather than __(), and for the reason set out in store-admin-role.php. */
$storeCashier->description = 'ขายหน้าร้าน เปิดและปิดกะจุดคิดเงิน รับชำระเงิน และดูรายการขาย';
$storeCashier->save();
$storeCashier->addPermissions( [ 'read.dashboard' ] );
$storeCashier->addPermissions( Permission::includes( '.profile' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeCashier->addPermissions( Permission::whereIn( 'namespace', [
    'nexopos.create.orders',
    'nexopos.read.orders',
    'nexopos.update.orders',
    'nexopos.void.orders',
    'nexopos.refund.orders',
    'nexopos.make-payment.orders',
    'nexopos.create.orders-instaments',
    'nexopos.update.orders-instaments',
    'nexopos.read.orders-instaments',
    'nexopos.customers.manage-account-history',
] )->get()->map( fn( $permission ) => $permission->namespace ) );

$storeCashier->addPermissions( Permission::includes( '.pos' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeCashier->addPermissions( Permission::whereIn( 'namespace', [
    ( new ProfileWidget )->getPermission(),
] )->get()->map( fn( $permission ) => $permission->namespace ) );
