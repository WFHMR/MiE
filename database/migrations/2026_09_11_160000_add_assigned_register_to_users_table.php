<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Binds a user to one till.
 *
 * A branch with several checkout stations needs each station to reach only its own till,
 * otherwise a cashier can open the till belonging to the next counter and the cash count
 * at the end of the shift no longer matches the drawer. NexoPOS ties an open till to a
 * single user through registers.used_by, so three stations working at once means three
 * users, and this column is what keeps each one on its own drawer.
 *
 * Null means unrestricted, which is how supervisors and the administrator are stored.
 */
return new class extends Migration
{
    private const TABLE = 'nexopos_users';

    public function up(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( Schema::hasColumn( self::TABLE, 'assigned_register_id' ) ) {
            return;
        }

        Schema::table( self::TABLE, function ( Blueprint $table ): void {
            $table->integer( 'assigned_register_id' )->nullable()->after( 'branch_id' );
            $table->index( 'assigned_register_id', 'idx_users_assigned_register' );
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( ! Schema::hasColumn( self::TABLE, 'assigned_register_id' ) ) {
            return;
        }

        Schema::table( self::TABLE, function ( Blueprint $table ): void {
            $table->dropIndex( 'idx_users_assigned_register' );
            $table->dropColumn( 'assigned_register_id' );
        } );
    }
};
