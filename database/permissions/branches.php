<?php

use App\Models\Permission;

if (defined('NEXO_CREATE_PERMISSIONS')) {
    $branches = Permission::firstOrNew(['namespace' => 'nexopos.create.branches']);
    $branches->name = __('Create Branches');
    $branches->namespace = 'nexopos.create.branches';
    $branches->description = __('Let the user create branches');
    $branches->save();

    $branches = Permission::firstOrNew(['namespace' => 'nexopos.delete.branches']);
    $branches->name = __('Delete Branches');
    $branches->namespace = 'nexopos.delete.branches';
    $branches->description = __('Let the user delete branches');
    $branches->save();

    $branches = Permission::firstOrNew(['namespace' => 'nexopos.update.branches']);
    $branches->name = __('Update Branches');
    $branches->namespace = 'nexopos.update.branches';
    $branches->description = __('Let the user update branches');
    $branches->save();

    $branches = Permission::firstOrNew(['namespace' => 'nexopos.read.branches']);
    $branches->name = __('Read Branches');
    $branches->namespace = 'nexopos.read.branches';
    $branches->description = __('Let the user read branches');
    $branches->save();
}
