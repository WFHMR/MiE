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
        Schema::create('csv_import_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->comment('Branch ID');
            $table->unsignedBigInteger('user_id')->comment('User who initiated import');
            $table->string('filename')->comment('Original CSV filename');
            $table->string('import_type', 20)->default('stock')->comment('Import type: stock or product');
            $table->string('status', 20)->default('pending')->comment('pending, processing, completed, failed');
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index('branch_id');
            $table->index('user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('csv_import_batches');
    }
};
