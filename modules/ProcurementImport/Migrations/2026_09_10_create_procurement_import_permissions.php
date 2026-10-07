<?php

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permissions = [
            [
                'name' => __('View CSV Import'),
                'namespace' => 'procurement.import.view',
                'description' => __('Allow viewing CSV import forms and previews'),
            ],
            [
                'name' => __('Upload & Import CSV'),
                'namespace' => 'procurement.import.create',
                'description' => __('Allow uploading CSV files and processing imports'),
            ],
            [
                'name' => __('Manage Import History'),
                'namespace' => 'procurement.import.manage',
                'description' => __('Allow viewing import history and deleting old imports'),
            ],
        ];

        foreach ($permissions as $permission) {
            DB::table('nexopos_permissions')->insertOrIgnore([
                'name' => $permission['name'],
                'namespace' => $permission['namespace'],
                'description' => $permission['description'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('nexopos_permissions')
            ->whereIn('namespace', [
                'procurement.import.view',
                'procurement.import.create',
                'procurement.import.manage',
            ])
            ->delete();
    }
};
