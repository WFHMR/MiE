<?php

/**
 * Table Migration: Add branch_id to products_histories
 * Purpose: Track stock movements per branch
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
        if (Schema::hasTable('nexopos_products_histories')) {
            Schema::table('nexopos_products_histories', function (Blueprint $table) {
                if (! Schema::hasColumn('nexopos_products_histories', 'branch_id')) {
                    $table->integer('branch_id')->nullable()->after('id');
                    $table->index(['branch_id', 'product_id']);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('nexopos_products_histories')) {
            Schema::table('nexopos_products_histories', function (Blueprint $table) {
                if (Schema::hasColumn('nexopos_products_histories', 'branch_id')) {
                    $table->dropIndex(['branch_id', 'product_id']);
                    $table->dropColumn('branch_id');
                }
            });
        }
    }
};
