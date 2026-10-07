<?php

use App\Models\Permission;

if (defined('NEXO_CREATE_PERMISSIONS')) {
    $permission = Permission::firstOrNew(['namespace' => 'nexopos.create.stock-transfers']);
    $permission->name = __('Create Stock Transfers');
    $permission->namespace = 'nexopos.create.stock-transfers';
    $permission->description = __('Let the user create stock transfers');
    $permission->save();

    $permission = Permission::firstOrNew(['namespace' => 'nexopos.delete.stock-transfers']);
    $permission->name = __('Delete Stock Transfers');
    $permission->namespace = 'nexopos.delete.stock-transfers';
    $permission->description = __('Let the user delete stock transfers');
    $permission->save();

    $permission = Permission::firstOrNew(['namespace' => 'nexopos.update.stock-transfers']);
    $permission->name = __('Update Stock Transfers');
    $permission->namespace = 'nexopos.update.stock-transfers';
    $permission->description = __('Let the user update stock transfers');
    $permission->save();

    $permission = Permission::firstOrNew(['namespace' => 'nexopos.read.stock-transfers']);
    $permission->name = __('Read Stock Transfers');
    $permission->namespace = 'nexopos.read.stock-transfers';
    $permission->description = __('Let the user read stock transfers');
    $permission->save();

    $permission = Permission::firstOrNew(['namespace' => 'nexopos.approve.stock-transfers']);
    $permission->name = __('Approve Stock Transfers');
    $permission->namespace = 'nexopos.approve.stock-transfers';
    $permission->description = __('Let the user approve pending stock transfers');
    $permission->save();
}
