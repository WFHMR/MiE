<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Skipped on a fresh database: the root set runs before the application creates its tables, so this
         * ALTER had no table to alter and failed the installation. The create migration for
         * procurements carries these three columns for a fresh install.
         */
        if ( ! Schema::hasTable( 'nexopos_procurements' ) || Schema::hasColumn( 'nexopos_procurements', 'billing_approval_status' ) ) {
            return;
        }

        Schema::table( 'nexopos_procurements', function ( Blueprint $table ): void {
            $table->string( 'billing_approval_status', 20 )->nullable();
            $table->unsignedBigInteger( 'billing_approved_by' )->nullable();
            $table->timestamp( 'billing_approved_at' )->nullable();
        } );
    }

    public function down(): void {}
};
