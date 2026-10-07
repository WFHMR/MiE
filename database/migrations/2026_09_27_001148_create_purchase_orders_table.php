<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nexopos_purchase_orders')) {
            Schema::create('nexopos_purchase_orders', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('branch_id')->index();
                $t->unsignedBigInteger('provider_id');
                $t->unsignedBigInteger('author_id');
                $t->string('name', 150);
                $t->string('reference', 100)->nullable();
                $t->date('expected_at')->nullable();
                $t->string('status', 20)->default('pending');
                $t->unsignedInteger('version')->default(1);
                $t->json('lines');
                $t->json('receipts');
                $t->timestamps();
            });
        }
        /**
         * SetupService runs the root set through "artisan migrate" before the application creates its own
         * tables, so on a fresh database nexopos_procurements does not exist yet. hasColumn() on a
         * missing table answers false rather than failing, so without this the ALTER below ran and
         * took the whole installation down with "no such table: nexopos_procurements". The columns
         * are carried for a fresh install by
         * database/migrations/create/2020_06_20_000000_create_procurements_table.php.
         */
        if (! Schema::hasTable('nexopos_procurements')) {
            return;
        }

        if (! Schema::hasColumn('nexopos_procurements', 'purchase_order_id')) {
            Schema::table('nexopos_procurements', function (Blueprint $t): void {
                $t->unsignedBigInteger('purchase_order_id')->nullable()->index();
            });
        }
        if (! Schema::hasColumn('nexopos_procurements', 'branch_id')) {
            Schema::table('nexopos_procurements', function (Blueprint $t): void {
                $t->unsignedBigInteger('branch_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Preserve purchase orders, receipt links and audit records during a code rollback.
    }
};
