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
        Schema::table('csv_import_batches', function (Blueprint $table) {
            $table->string('import_type', 20)->default('stock')->after('filename')->comment('Import type: stock or product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('csv_import_batches', function (Blueprint $table) {
            $table->dropColumn('import_type');
        });
    }
};
