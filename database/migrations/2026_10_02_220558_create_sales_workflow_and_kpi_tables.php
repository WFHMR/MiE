<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nexopos_sales_quotations')) {
            Schema::create('nexopos_sales_quotations', function (Blueprint $t) {
                $t->id();
                $t->uuid('request_key')->unique();
                $t->unsignedBigInteger('branch_id')->index();
                $t->unsignedBigInteger('customer_id');
                $t->json('customer_snapshot');
                $t->json('pricing');
                $t->unsignedBigInteger('author_id');
                $t->string('name', 150);
                $t->date('valid_until');
                $t->text('terms')->nullable();
                $t->json('lines');
                $t->decimal('total', 20, 5);
                $t->decimal('tax', 20, 5);
                $t->string('status', 20)->default('draft');
                $t->unsignedInteger('version')->default(1);
                $t->timestamp('issued_at')->nullable();
                $t->timestamp('accepted_at')->nullable();
                $t->unsignedBigInteger('accepted_by')->nullable();
                $t->text('acceptance_note')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('nexopos_sales_documents')) {
            Schema::create('nexopos_sales_documents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('quotation_id')->unique();
                $t->unsignedBigInteger('branch_id')->index();
                $t->unsignedBigInteger('customer_id');
                $t->unsignedBigInteger('author_id');
                $t->json('lines');
                $t->decimal('total', 20, 5);
                $t->string('stock_timing', 20);
                $t->string('status', 20)->default('confirmed');
                $t->unsignedBigInteger('order_id')->nullable()->unique();
                $t->date('due_at');
                $t->text('shipping_address');
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('nexopos_sales_deliveries')) {
            Schema::create('nexopos_sales_deliveries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sales_document_id')->unique();
                $t->unsignedBigInteger('branch_id')->index();
                $t->unsignedBigInteger('author_id');
                $t->string('status', 20)->default('preparing');
                $t->string('carrier', 150)->nullable();
                $t->string('tracking', 150)->nullable();
                $t->text('note')->nullable();
                $t->timestamp('dispatched_at')->nullable();
                $t->unsignedBigInteger('dispatched_by')->nullable();
                $t->timestamp('delivered_at')->nullable();
                $t->unsignedBigInteger('delivered_by')->nullable();
                $t->string('received_by', 150)->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('nexopos_kpi_targets')) {
            Schema::create('nexopos_kpi_targets', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('branch_id');
                $t->date('month');
                $t->string('metric', 50);
                $t->decimal('target', 20, 5);
                $t->unsignedBigInteger('author_id');
                $t->timestamps();
                $t->unique(['branch_id', 'month', 'metric'], 'kpi_branch_month_metric');
            });
        }
    }

    public function down(): void
    { /* Preserve issued documents and KPI history on code rollback. */
    }
};
