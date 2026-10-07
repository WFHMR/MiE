<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ( ! Schema::hasColumn( 'nexopos_loyalty_entries', 'shared_balance' ) ) {
            Schema::table( 'nexopos_loyalty_entries', function ( Blueprint $t ): void {
                $t->bigInteger( 'shared_balance' )->nullable();
            } );
        }
        if ( ! Schema::hasColumn( 'nexopos_loyalty_entries', 'branch_id' ) ) {
            Schema::table( 'nexopos_loyalty_entries', function ( Blueprint $t ): void {
                $t->unsignedBigInteger( 'branch_id' )->nullable();
            } );
        }
        if ( ! DB::table( 'nexopos_loyalty_settings' )->where( 'branch_id', 0 )->exists() ) {
            $rules = DB::table( 'nexopos_loyalty_settings' )->orderBy( 'branch_id' )->get();
            $distinct = $rules->map( fn( $s ) => [$s->enabled, $s->earn_satang, $s->redeem_points, $s->redeem_satang] )->unique()->count();
            if ( $distinct === 1 ) {
                $rule = (array) $rules->first();
                $rule['branch_id'] = 0;
                DB::table( 'nexopos_loyalty_settings' )->insert( $rule );
            }
        }
    }

    public function down(): void
    {
        // Preserve financial audit columns and shared settings on rollback.
        // Reverting shared balances to independent branches requires a reviewed reconciliation.
    }
};
