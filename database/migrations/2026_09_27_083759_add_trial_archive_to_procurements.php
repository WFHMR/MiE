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
         * procurements carries both columns for a fresh install.
         */
        if ( ! Schema::hasTable( 'nexopos_procurements' ) || Schema::hasColumn( 'nexopos_procurements', 'trial_archived_at' ) ) {
            return;
        }

        Schema::table( 'nexopos_procurements', function ( Blueprint $table ): void {
            $table->timestamp( 'trial_archived_at' )->nullable();
            $table->unsignedBigInteger( 'trial_archived_by' )->nullable();
        } );
    }

    public function down(): void {}
};
