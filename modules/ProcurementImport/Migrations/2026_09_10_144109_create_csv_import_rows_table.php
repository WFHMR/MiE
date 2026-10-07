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
        Schema::create('csv_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id')->comment('CSV Import Batch ID');
            $table->integer('row_number')->comment('Row number in CSV file');
            $table->string('status', 20)->default('pending')->comment('pending, success, error');
            $table->json('row_data')->comment('Original CSV row data');
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->foreign('batch_id')->references('id')->on('csv_import_batches')->onDelete('cascade');
            $table->index(['batch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('csv_import_rows');
    }
};
