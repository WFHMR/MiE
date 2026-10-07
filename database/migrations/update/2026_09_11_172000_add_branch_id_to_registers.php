<?php

/**
 * Table Migration
 * Adds branch_id to registers so each branch owns its own tills.
 *
 * Two earlier attempts at this column sit in the root database/migrations folder. That folder
 * runs before NexoPOS builds its own tables during an installation, so the guard finds no
 * registers table, skips without error, and the column ends up recorded as added while being
 * absent. Verified on a freshly built database.
 *
 * The column matters beyond the till picker: without it a branch cannot be told whether it is
 * mid-sale, which is the check that stops a catalogue rebuild landing under a cashier.
 **/

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

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
         * Tills that predate multi branch belong to the main branch. Left null they appear in
         * every branch's till picker, which is what let a branch cashier open the till the
         * administrator was already holding.
         */
        if ( ! Schema::hasTable( 'nexopos_branches' ) ) {
            return;
        }

        $mainBranchId = DB::table( 'nexopos_branches' )->where( 'is_main', 1 )->value( 'id' );

        if ( $mainBranchId !== null ) {
            DB::table( self::TABLE )->whereNull( 'branch_id' )->update( [ 'branch_id' => $mainBranchId ] );
        }
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( ! Schema::hasColumn( self::TABLE, 'branch_id' ) ) {
            return;
        }

        Schema::table( self::TABLE, function ( Blueprint $table ): void {
            $table->dropIndex( 'idx_registers_branch' );
            $table->dropColumn( 'branch_id' );
        } );
    }
};
