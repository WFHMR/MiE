<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds branch_id to nexopos_registers so each branch owns its own tills.
 *
 * Replaces 2026_09_08_233100_add_branch_id_to_registers_table, which is recorded as run
 * but never altered the table. That migration called App\Classes\Schema::hasTable() with
 * an already-prefixed name, and the wrapper pushes every name through the
 * ns-table-name hook, so the guard asked for nexopos_nexopos_registers, got false, and
 * skipped the body without error. This migration uses the framework facade with the real
 * table name so the guard evaluates what it claims to.
 */
return new class extends Migration
{
    private const TABLE = 'nexopos_registers';

    public function up(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( ! Schema::hasColumn( self::TABLE, 'branch_id' ) ) {
            Schema::table( self::TABLE, function ( Blueprint $table ): void {
                $table->integer( 'branch_id' )->nullable()->after( 'id' );
                $table->index( 'branch_id', 'idx_registers_branch' );
            } );
        }

        /**
         * Existing tills predate multi branch and belong to the main branch. Left at NULL
         * they would appear in every branch's till picker, which is what let the Tha Chang
         * cashier see the main branch till and try to open one already held by the admin.
         */
        $mainBranchId = DB::table( 'nexopos_branches' )
            ->where( 'is_main', 1 )
            ->value( 'id' );

        if ( $mainBranchId !== null ) {
            DB::table( self::TABLE )
                ->whereNull( 'branch_id' )
                ->update( [ 'branch_id' => $mainBranchId ] );
        }
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( Schema::hasColumn( self::TABLE, 'branch_id' ) ) {
            Schema::table( self::TABLE, function ( Blueprint $table ): void {
                $table->dropIndex( 'idx_registers_branch' );
                $table->dropColumn( 'branch_id' );
            } );
        }
    }
};
