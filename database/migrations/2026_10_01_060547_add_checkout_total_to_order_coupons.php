<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Skipped on a fresh database: the root set runs before the application creates its tables, and
         * hasColumn() on a missing table answers false rather than failing, so this reached an
         * ALTER with no table and failed the installation. The create migration for order coupons
         * carries the column for a fresh install.
         */
        if ( ! Schema::hasTable( 'nexopos_orders_coupons' ) ) {
            return;
        }

        if ( ! Schema::hasColumn( 'nexopos_orders_coupons', 'checkout_total_satang' ) ) {
            Schema::table( 'nexopos_orders_coupons', fn ( Blueprint $t ) => $t->unsignedBigInteger( 'checkout_total_satang' )->nullable() );
        }
    }

    public function down(): void
    {
        // Preserve the financial snapshot used to limit promotion refunds.
    }
};
