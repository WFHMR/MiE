<?php

/**
 * Table Migration
 *
 * Stores the VAT rates along with the period during which each rate is legally
 * in force.
 *
 * Thailand does not have a single permanent VAT rate. Section 80 of the Revenue
 * Code sets the statutory rate at 10%, and a Royal Decree reduces it to 7% for a
 * fixed period that is renewed roughly every year. A sale therefore has to be
 * taxed with the rate that was in force on the day it happened, otherwise every
 * tax invoice reprinted after a rate change would show a different VAT amount
 * than the one actually collected and already reported on the PP30 return.
 *
 * @since v6.3.0
 **/

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if ( ! Schema::hasTable( 'nexopos_vat_rates' ) ) {
            Schema::createIfMissing( 'nexopos_vat_rates', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );
                $table->string( 'uuid' )->nullable();
                $table->string( 'label' );

                /**
                 * 4 decimals rather than 2: a rate is a percentage, not money, and
                 * reduced rates on specific goods are sometimes expressed with
                 * fractions of a percent.
                 */
                $table->decimal( 'rate', 8, 4 )->default( 0 );

                /**
                 * A null "effective_to" means the rate is open ended. Only one rate
                 * may be open ended at a time, which ThaiVatService enforces when a
                 * new period is opened.
                 */
                $table->date( 'effective_from' );
                $table->date( 'effective_to' )->nullable();

                /**
                 * The decree or section the rate comes from. Printed on nothing, but
                 * an auditor asking "why 7%" needs an answer that lives with the data.
                 */
                $table->string( 'legal_reference' )->nullable();

                $table->integer( 'author_id' )->nullable();
                $table->timestamps();

                $table->index( 'effective_from' );
                $table->index( 'effective_to' );
            } );

            /**
             * Seeded here rather than in a seeder so the till never runs without a
             * rate. The start date is deliberately far in the past: an installation
             * importing historical orders must not find them untaxed.
             *
             * A raw insert is used because the options table may not be populated at
             * this point in the migration order, and because the Eloquent model would
             * pull in events that have nothing to do with schema setup.
             */
            DB::table( 'nexopos_vat_rates' )->insert( [
                'uuid' => (string) Str::uuid(),
                'label' => 'VAT 7%',
                'rate' => 7,
                'effective_from' => '2000-01-01',
                'effective_to' => null,
                'legal_reference' => 'อัตราลดตามพระราชกฤษฎีกา (มาตรา 80 แห่งประมวลรัษฎากรกำหนดอัตรา 10%)',
                'author_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ] );
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if ( Schema::hasTable( 'nexopos_vat_rates' ) ) {
            Schema::dropIfExists( 'nexopos_vat_rates' );
        }
    }
};
