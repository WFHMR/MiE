<?php

/**
 * Table Migration
 *
 * Adds credit payment terms to customer groups on an installation that already exists.
 *
 * A shop sells to two kinds of buyer. Walk-in customers pay at the counter. Trade
 * customers take the goods now and settle the invoice within an agreed number of days
 * ("net 30"). Until now NexoPOS could only express the second case as a layaway, which
 * holds the stock instead of releasing it, so the shop's inventory kept counting goods
 * that had already left the building.
 *
 * "payment_term_days" is the number of days granted to every customer of the group. 0
 * keeps the existing behaviour, so groups created before this migration are unaffected.
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
        if ( Schema::hasTable( 'nexopos_customers_groups' ) && ! Schema::hasColumn( 'nexopos_customers_groups', 'payment_term_days' ) ) {
            Schema::table( 'nexopos_customers_groups', function ( Blueprint $table ) {
                $table->integer( 'payment_term_days' )->default( 0 )->after( 'minimal_credit_payment' );
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
        if ( Schema::hasTable( 'nexopos_customers_groups' ) && Schema::hasColumn( 'nexopos_customers_groups', 'payment_term_days' ) ) {
            Schema::table( 'nexopos_customers_groups', function ( Blueprint $table ) {
                $table->dropColumn( 'payment_term_days' );
            } );
        }
    }
};
