<?php

use App\Models\Permission;
use App\Models\Role;

/**
 * The description is written in Thai directly rather than through __(), for the same reason the
 * sidebar is: this text is stored in the database at install time, so whatever language it is
 * written in here is the language the shop reads for the life of the installation. Passing it
 * through __() would have resolved it once, against whatever locale happened to be active during
 * the install, and frozen that.
 *
 * It also carried the name of the upstream project it was built from. That string was not a
 * comment — it is the Description field of the role, shown whenever this role is opened for
 * editing at ผู้ใช้งาน → สร้างกลุ่มผู้ใช้งาน. The shop trades under its own name and the
 * software is presented as its own, so a supplier's product name has no business appearing on
 * a screen its staff open.
 */
$storeAdmin = Role::firstOrNew( [ 'namespace' => 'nexopos.store.administrator' ] );
$storeAdmin->name = __( 'Store Administrator' );
$storeAdmin->namespace = 'nexopos.store.administrator';
$storeAdmin->locked = true;
$storeAdmin->description = 'ดูแลร้านได้ทั้งหมด ตั้งแต่สินค้า คลังสินค้า การขาย ลูกค้า จัดซื้อ รายงาน และภาษี';
$storeAdmin->save();

$storeAdmin->addPermissions( [ 'read.dashboard' ] );
$storeAdmin->addPermissions( Permission::includes( '.transactions' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.cash-flow-history' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.transactions-accounts' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.categories' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.customers' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.customers-groups' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.coupons' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.orders' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.procurements' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.providers' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.products' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.products-history' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.products-adjustments' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.products-units' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.profile' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.registers' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.registers-history' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.rewards' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.reports.' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.stores' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.taxes' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.tax-invoices' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.billing-notes' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.withholding-tax' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.trucks' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.units' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.manage-payments-types' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '.pos' )->get()->map( fn( $permission ) => $permission->namespace ) );
$storeAdmin->addPermissions( Permission::includes( '-widget' )->get()->map( fn( $permission ) => $permission->namespace ) );
