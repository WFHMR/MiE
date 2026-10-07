<?php

use App\Models\Permission;
use App\Models\Role;
use App\Widgets\ProfileWidget;

$storeCustomer = Role::firstOrNew( [ 'namespace' => 'nexopos.store.customer' ] );
$storeCustomer->name = __( 'Store Customer' );
$storeCustomer->namespace = 'nexopos.store.customer';
$storeCustomer->locked = true;
/** Thai literal rather than __(), and for the reason set out in store-admin-role.php. */
$storeCustomer->description = 'ซื้อสินค้าและแก้ไขข้อมูลส่วนตัวของตัวเองได้';
$storeCustomer->save();

$storeCustomer->addPermissions( [ 'read.dashboard' ] );
$storeCustomer->addPermissions( Permission::includes( '.profile' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeCustomer->addPermissions( Permission::whereIn( 'namespace', [
    ( new ProfileWidget )->getPermission(),
] )->get()->map( fn( $permission ) => $permission->namespace ) );
