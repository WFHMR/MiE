<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nexopos_stock_counts')) {
            Schema::create('nexopos_stock_counts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('author_id')->index();
                $table->string('title', 150);
                $table->string('status', 20)->default('draft');
                $table->unsignedInteger('version')->default(1);
                $table->json('lines');
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
            });
        }
        /**
         * The permission rows cannot be written during a fresh installation.
         *
         * This file is at the root of database/migrations, and SetupService runs the root set
         * through "php artisan migrate" *before* the application creates its own tables. On a clean
         * database the query below therefore ran against a nexopos_permissions table that did not
         * exist yet, the exception took "artisan migrate" down with it, and setup failed at
         * "no such table: nexopos_permissions" — so the application could not be installed at all,
         * and the test suite could not build its database either.
         *
         * The same guard is on the two other root migrations that grant a permission. The fresh
         * install gets these two rows from database/permissions/stock-counts.php, which the core
         * permission migration includes; this block is for the installation that already exists.
         */
        if ( ! Schema::hasTable('nexopos_permissions') || ! Schema::hasTable('nexopos_roles') ) {
            return;
        }

        foreach (['work' => 'ตรวจนับสต๊อก: กรอกและตรวจยอดนับ', 'confirm' => 'ตรวจนับสต๊อก: ยืนยันปรับยอดจริง'] as $key => $name) {
            $namespace = 'nexopos.stock-counts.'.$key;
            $permission = Permission::where('namespace', $namespace)->first();
            if (! $permission) {
                if (Permission::where('name', $name)->exists()) {
                    throw new RuntimeException('Stock count permission name collision: '.$namespace);
                }
                $permission = new Permission;
                $permission->namespace = $namespace;
                $permission->name = $name;
                $permission->description = $name;
                $permission->save();
            }
            $admin = Role::where('namespace', 'admin')->first();
            if ($admin && ! RolePermission::where('role_id', $admin->id)->where('permission_id', $permission->id)->exists()) {
                $relation = new RolePermission;
                $relation->role_id = $admin->id;
                $relation->permission_id = $permission->id;
                $relation->save();
            }
            if ($admin) {
                foreach ($admin->users as $user) {
                    Cache::forget('ns-all-permissions-'.$user->id);
                }
            }
        }
    }

    public function down(): void
    {
        // Keep count records and their audit trail when rolling application code back.
    }
};
