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
        if ( ! Schema::hasTable( 'nexopos_orders_coupons' ) ) {
            Schema::createIfMissing( 'nexopos_orders_coupons', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );
                $table->string( 'code' );
                $table->string( 'name' );
                $table->integer( 'customer_coupon_id' );
                $table->integer( 'coupon_id' );
                $table->integer( 'order_id' );
                $table->string( 'type' ); // discount_percentage, flat_percentage
                $table->decimal( 'discount_value', 18, 5 );
                $table->decimal( 'minimum_cart_value', 18, 5 )->default( 0 );
                $table->decimal( 'maximum_cart_value', 18, 5 )->default( 0 );
                $table->integer( 'limit_usage' )->default( 0 );
                $table->decimal( 'value', 18, 5 )->default( 0 );
                $table->integer( 'author_id' );
                $table->boolean( 'counted' )->default( false );

                /**
                 * The cart total the promotion was granted against, kept so a refund cannot give
                 * back more than the promotion was worth at the time.
                 *
                 * Declared here as well as in database/migrations/2026_10_01_060547, because that
                 * file is at the root of database/migrations and the root set runs before this one —
                 * so on a fresh installation it is skipped.
                 */
                $table->unsignedBigInteger( 'checkout_total_satang' )->nullable();
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
        if ( Schema::hasTable( 'nexopos_orders_coupons' ) ) {
            Schema::dropIfExists( 'nexopos_orders_coupons' );
        }
    }
};
