<?php

/**
 * Table Migration
 *
 * Adds the Thai VAT classification to products and to the order lines they
 * produce.
 *
 * Products carry the classification so the till knows how to tax them. Order
 * products carry a copy of the classification and of the rate that was applied,
 * because a tax invoice has to be reproducible: once a sale is reported on a
 * PP30 return, changing the product later must not change the historical
 * document.
 *
 * The matching columns are declared in the "create" migrations as well, since a
 * fresh installation marks every "update" migration as executed without running
 * it (see SetupService::runMigration).
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
        if ( Schema::hasTable( 'nexopos_products' ) ) {
            if ( ! Schema::hasColumn( 'nexopos_products', 'vat_status' ) ) {
                Schema::table( 'nexopos_products', function ( Blueprint $table ) {
                    $table->string( 'vat_status' )->default( 'standard' )->after( 'tax_value' );
                } );
            }
        }

        if ( Schema::hasTable( 'nexopos_orders_products' ) ) {
            Schema::table( 'nexopos_orders_products', function ( Blueprint $table ) {
                if ( ! Schema::hasColumn( 'nexopos_orders_products', 'vat_status' ) ) {
                    $table->string( 'vat_status' )->default( 'standard' )->after( 'tax_type' );
                }

                if ( ! Schema::hasColumn( 'nexopos_orders_products', 'vat_rate' ) ) {
                    $table->decimal( 'vat_rate', 8, 4 )->default( 0 )->after( 'vat_status' );
                }
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
        if ( Schema::hasTable( 'nexopos_products' ) ) {
            if ( Schema::hasColumn( 'nexopos_products', 'vat_status' ) ) {
                Schema::table( 'nexopos_products', function ( Blueprint $table ) {
                    $table->dropColumn( 'vat_status' );
                } );
            }
        }

        if ( Schema::hasTable( 'nexopos_orders_products' ) ) {
            $columns = collect( [ 'vat_status', 'vat_rate' ] )
                ->filter( fn( $column ) => Schema::hasColumn( 'nexopos_orders_products', $column ) )
                ->values()
                ->toArray();

            if ( ! empty( $columns ) ) {
                Schema::table( 'nexopos_orders_products', function ( Blueprint $table ) use ( $columns ) {
                    $table->dropColumn( $columns );
                } );
            }
        }
    }
};
