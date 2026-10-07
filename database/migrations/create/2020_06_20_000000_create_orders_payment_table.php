<?php

/**
 * Table Migration
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
        if ( ! Schema::hasTable( 'nexopos_orders_payments' ) ) {
            Schema::createIfMissing( 'nexopos_orders_payments', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );
                $table->integer( 'order_id' );
                $table->decimal( 'value', 18, 5 )->default( 0 );
                $table->integer( 'author_id' );
                $table->string( 'identifier' );

                /**
                 * What the cashier typed in to identify the transfer on the other side:
                 * a PromptPay slip reference, a welfare card terminal approval code, a
                 * bank transfer time. Needed because for those channels the money arrives
                 * on a statement the shop has to match the till against, one line at a
                 * time. Optional: cash has nothing to reconcile. @since v6.3.0
                 */
                $table->string( 'reference' )->nullable();
                $table->string( 'uuid' )->nullable();
                $table->timestamps();
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
        if ( Schema::hasTable( 'nexopos_orders_payments' ) ) {
            Schema::dropIfExists( 'nexopos_orders_payments' );
        }
    }
};
