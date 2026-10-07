<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nexopos_purchase_requests')) {
            Schema::create('nexopos_purchase_requests', function (Blueprint $t): void {
                $t->id();
                $t->uuid('request_key')->unique();
                $t->unsignedBigInteger('branch_id')->index();
                $t->unsignedBigInteger('author_id');
                $t->string('name', 150);
                $t->string('department', 150)->nullable();
                $t->text('reason');
                $t->date('needed_at')->nullable();
                $t->string('status', 20)->default('submitted');
                $t->json('lines');
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->text('decision_note')->nullable();
                $t->unsignedBigInteger('purchase_order_id')->nullable()->unique();
                $t->unsignedBigInteger('selected_quote_id')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('nexopos_supplier_quotations')) {
            Schema::create('nexopos_supplier_quotations', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('purchase_request_id')->index();
                $t->unsignedBigInteger('provider_id');
                $t->unsignedBigInteger('author_id');
                $t->string('reference', 100);
                $t->date('valid_until');
                $t->date('delivery_date')->nullable();
                $t->unsignedInteger('credit_days')->default(0);
                $t->text('terms')->nullable();
                $t->json('lines');
                $t->decimal('total', 20, 5);
                $t->timestamps();
                $t->unique(['purchase_request_id', 'provider_id', 'reference'], 'supplier_quote_ref_unique');
            });
        }
        if (! Schema::hasTable('nexopos_purchase_returns')) {
            Schema::create('nexopos_purchase_returns', function (Blueprint $t): void {
                $t->id();
                $t->uuid('request_key')->unique();
                $t->unsignedBigInteger('branch_id')->index();
                $t->unsignedBigInteger('provider_id');
                $t->unsignedBigInteger('procurement_id')->index();
                $t->unsignedBigInteger('author_id');
                $t->string('mode', 20);
                $t->string('status', 20)->default('draft');
                $t->text('reason');
                $t->json('lines');
                $t->decimal('total', 20, 5);
                $t->unsignedBigInteger('confirmed_by')->nullable();
                $t->timestamp('confirmed_at')->nullable();
                $t->unsignedBigInteger('settled_by')->nullable();
                $t->timestamp('settled_at')->nullable();
                $t->string('settlement_reference', 150)->nullable();
                $t->text('settlement_note')->nullable();
                $t->timestamps();
            });
        }
        /**
         * The three tables above are this migration's own, so creating them at the root of
         * database/migrations is safe. This column is not: it is added to a the application table, and the
         * root set runs before the application creates its tables. hasColumn() on a missing table answers
         * false rather than failing, so on a fresh database this reached the ALTER and failed the
         * installation. The create migration for procurements carries the column.
         */
        if (! Schema::hasTable('nexopos_procurements')) {
            return;
        }

        if (! Schema::hasColumn('nexopos_procurements', 'purchase_return_credit')) {
            Schema::table('nexopos_procurements', function (Blueprint $t): void {
                $t->decimal('purchase_return_credit', 20, 5)->default(0);
            });
        }
    }

    public function down(): void
    {
        // Keep approval, supplier-return and settlement evidence on code rollback.
    }
};
