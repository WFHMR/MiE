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
        if (! Schema::hasColumn('nexopos_procurements', 'receipt_source')) {
            Schema::table('nexopos_procurements', function (Blueprint $table) {
                $table->string('receipt_source', 20)->nullable();
                $table->uuid('receipt_request_key')->nullable()->unique('ns_receipt_request_unique');
                $table->text('receipt_note')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Preserve receipt provenance and idempotency keys during a code rollback.
    }
};
