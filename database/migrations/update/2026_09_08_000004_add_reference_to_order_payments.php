<?php

/**
 * Table Migration
 *
 * Adds the payment reference to order payments on an installation that already exists.
 *
 * Cash needs no reference. Every other channel does: a PromptPay transfer, a bank
 * transfer and a state welfare card charge all arrive on a statement the shop receives
 * later, and reconciling the till against that statement means matching one payment to
 * one line. A daily total cannot answer "which transfer is missing".
 *
 * The matching column is declared in the "create" migration as well, since a fresh
 * installation marks every "update" migration as executed without running it (see
 * SetupService::runMigration).
 *
 * @since v6.3.0
 **/

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if ( Schema::hasTable( 'nexopos_orders_payments' ) && ! Schema::hasColumn( 'nexopos_orders_payments', 'reference' ) ) {
            Schema::table( 'nexopos_orders_payments', function ( Blueprint $table ) {
                $table->string( 'reference' )->nullable()->after( 'identifier' );
            } );
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if ( Schema::hasTable( 'nexopos_orders_payments' ) && Schema::hasColumn( 'nexopos_orders_payments', 'reference' ) ) {
            Schema::table( 'nexopos_orders_payments', function ( Blueprint $table ) {
                $table->dropColumn( 'reference' );
            } );
        }
    }
};
