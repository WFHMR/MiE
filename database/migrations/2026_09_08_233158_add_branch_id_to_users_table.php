<?php

/**
 * Table Migration: Add branch_id to users
 * Purpose: Assign users to specific branches
 **/

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('nexopos_users')) {
            Schema::table('nexopos_users', function (Blueprint $table) {
                if (! Schema::hasColumn('nexopos_users', 'branch_id')) {
                    $table->integer('branch_id')->nullable()->after('id');
                    $table->index('branch_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('nexopos_users')) {
            Schema::table('nexopos_users', function (Blueprint $table) {
                if (Schema::hasColumn('nexopos_users', 'branch_id')) {
                    $table->dropColumn('branch_id');
                }
            });
        }
    }
};
