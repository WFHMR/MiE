<?php

use App\Models\Permission;

/**
 * The stock count permissions were only ever created by the root migration that creates the stock
 * counts table — and that migration runs before the application builds its own tables, so on a fresh
 * install it found no permissions table and brought the whole installation down with it. Declared
 * here as well, the fresh install gets them from the core permission migration like every other
 * permission, and the root migration can skip the part it cannot do.
 */
if ( defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
    foreach ( [
        'work' => __( 'ตรวจนับสต๊อก: กรอกและตรวจยอดนับ' ),
        'confirm' => __( 'ตรวจนับสต๊อก: ยืนยันปรับยอดจริง' ),
    ] as $key => $name ) {
        $namespace = 'nexopos.stock-counts.' . $key;

        $permission = Permission::firstOrNew( [ 'namespace' => $namespace ] );
        $permission->name = $name;
        $permission->namespace = $namespace;
        $permission->description = $name;
        $permission->save();
    }
}
