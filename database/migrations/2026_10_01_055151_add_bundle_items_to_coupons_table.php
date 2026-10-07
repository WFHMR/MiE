<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /**
         * The root set runs before the application creates its own tables, and hasColumn() on a table that
         * does not exist answers false rather than failing — so on a fresh database this went
         * straight to an ALTER with nothing to alter and failed the installation. The create
         * migration for coupons carries the column for a fresh install.
         */
        if ( ! Schema::hasTable( 'nexopos_coupons' ) ) {
            return;
        }

        if ( ! Schema::hasColumn( 'nexopos_coupons', 'bundle_items' ) ) {
            Schema::table( 'nexopos_coupons', fn ( Blueprint $table ) => $table->json( 'bundle_items' )->nullable() );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep definitions referenced by existing promotion receipts on rollback.
    }
};
