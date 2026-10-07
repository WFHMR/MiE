<?php

/**
 * Table Migration
 * Adds stock_on_base_unit to products, marking the goods whose stock is held on one unit
 * while their other units are price labels rather than separate stock.
 *
 * NexoPOS normally keeps an independent figure per unit, so a product stocked as ten pieces
 * and nine thousand dozens can sell from either without one touching the other. That suits
 * goods genuinely received in both forms and has to keep working.
 *
 * A wholesaler that receives cartons and breaks them open on the shelf has the opposite need:
 * one physical pile, several barcodes pointing at it, and a cashier who scans whichever label
 * is facing them. A figure per unit there would let the same carton be sold once whole and
 * again as its contents.
 *
 * Both models cannot be the default, so the second is opt in per product. The column defaults
 * to false, leaving every existing and future ordinary product on today's behaviour.
 *
 * Kept in step with database/migrations/create/2020_06_20_000000_create_products_table.php,
 * which carries the same column for a fresh installation.
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
            if ( ! Schema::hasColumn( 'nexopos_products', 'stock_on_base_unit' ) ) {
                Schema::table( 'nexopos_products', function ( Blueprint $table ) {
                    $table->boolean( 'stock_on_base_unit' )->default( false )->after( 'searchable' );
                } );
            }
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
            if ( Schema::hasColumn( 'nexopos_products', 'stock_on_base_unit' ) ) {
                Schema::table( 'nexopos_products', function ( Blueprint $table ) {
                    $table->dropColumn( 'stock_on_base_unit' );
                } );
            }
        }
    }
};
