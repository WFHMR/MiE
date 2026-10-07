<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('nexopos_branches', function (Blueprint $table) {
            $table->boolean('is_main_warehouse')->default(false)->after('is_main');
        });

        // Set the main branch as the main warehouse by default
        DB::table('nexopos_branches')
            ->where('is_main', true)
            ->update(['is_main_warehouse' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nexopos_branches', function (Blueprint $table) {
            $table->dropColumn('is_main_warehouse');
        });
    }
};
