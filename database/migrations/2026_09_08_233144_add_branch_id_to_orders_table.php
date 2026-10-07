<?php

/**
 * Table Migration: Add branch_id to orders
 * Purpose: Track orders per branch
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
        if (Schema::hasTable('nexopos_orders')) {
            Schema::table('nexopos_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('nexopos_orders', 'branch_id')) {
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
        if (Schema::hasTable('nexopos_orders')) {
            Schema::table('nexopos_orders', function (Blueprint $table) {
                if (Schema::hasColumn('nexopos_orders', 'branch_id')) {
                    $table->dropColumn('branch_id');
                }
            });
        }
    }
};
