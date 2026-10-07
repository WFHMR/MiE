<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('nexopos_procurements', 'branch_id')) {
            Schema::table('nexopos_procurements', function (Blueprint $table) {
                $table->unsignedBigInteger('branch_id')->default(1)->after('id');
                $table->index('branch_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nexopos_procurements', 'branch_id')) {
            Schema::table('nexopos_procurements', function (Blueprint $table) {
                $table->dropIndex(['branch_id']);
                $table->dropColumn('branch_id');
            });
        }
    }
};
