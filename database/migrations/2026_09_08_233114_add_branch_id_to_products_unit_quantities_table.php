<?php

/**
 * Table Migration: Add branch_id to products_unit_quantities
 * Purpose: Separate inventory per branch
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
        if (Schema::hasTable('nexopos_products_unit_quantities')) {
            Schema::table('nexopos_products_unit_quantities', function (Blueprint $table) {
                if (! Schema::hasColumn('nexopos_products_unit_quantities', 'branch_id')) {
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
        if (Schema::hasTable('nexopos_products_unit_quantities')) {
            Schema::table('nexopos_products_unit_quantities', function (Blueprint $table) {
                if (Schema::hasColumn('nexopos_products_unit_quantities', 'branch_id')) {
                    $table->dropIndex(['branch_id', 'product_id']);
                    $table->dropColumn('branch_id');
                }
            });
        }
    }
};
