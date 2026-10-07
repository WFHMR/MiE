<?php

/**
 * CSV Import Batches Table
 * Tracks each CSV import session per branch
 */

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nexopos_csv_import_batches')) {
            Schema::create('nexopos_csv_import_batches', function (Blueprint $table) {
                $table->id();
                $table->integer('branch_id');
                $table->integer('user_id');
                $table->string('filename');
                $table->string('status')->default('pending'); // pending, processing, completed, failed
                $table->integer('total_rows')->default(0);
                $table->integer('processed_rows')->default(0);
                $table->integer('success_count')->default(0);
                $table->integer('error_count')->default(0);
                $table->text('errors')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                
                $table->index('branch_id');
                $table->index('user_id');
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('nexopos_csv_import_batches')) {
            Schema::dropIfExists('nexopos_csv_import_batches');
        }
    }
};
