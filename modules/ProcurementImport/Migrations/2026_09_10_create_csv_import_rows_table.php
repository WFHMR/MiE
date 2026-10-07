<?php

/**
 * CSV Import Rows Table
 * Tracks individual row processing results
 */

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nexopos_csv_import_rows')) {
            Schema::create('nexopos_csv_import_rows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('nexopos_csv_import_batches')->onDelete('cascade');
                $table->integer('row_number');
                $table->string('status')->default('pending'); // pending, success, error
                $table->text('row_data'); // JSON of CSV row data
                $table->text('error_message')->nullable();
                $table->timestamps();
                
                $table->index('batch_id');
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('nexopos_csv_import_rows')) {
            Schema::dropIfExists('nexopos_csv_import_rows');
        }
    }
};
