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
        Schema::table('nexopos_products_unit_quantities', function (Blueprint $table) {
            $table->decimal('reserved_quantity', 18, 5)->default(0);
        });
        Schema::create('nexopos_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_document_id')->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('stock_row_id')->index();
            $table->decimal('quantity', 18, 5);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('author_id');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Preserve reservation history and stock holds on code rollback.
    }
};
