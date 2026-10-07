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
        if ( ! Schema::hasTable( 'nexopos_procurements' ) ) {
            Schema::createIfMissing( 'nexopos_procurements', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );
                $table->string( 'name' );
                $table->integer( 'provider_id' );
                $table->decimal( 'value', 18, 5 )->default( 0 );
                $table->decimal( 'cost', 18, 5 )->default( 0 );
                $table->decimal( 'tax_value', 18, 5 )->default( 0 );
                $table->string( 'invoice_reference' )->nullable();
                /**
                 * The paperwork a purchase actually travels with, and the reason the receiving desk
                 * and the accounts desk can do their jobs from this screen.
                 *
                 * Receiving checks the goods against the supplier's delivery note; accounts pays
                 * against the billing note and has to know which receipt it belongs to, or the
                 * payment is filed against the wrong one.
                 *
                 * Kept in step with
                 * database/migrations/update/2026_09_19_090000_add_receiving_and_billing_fields_to_procurements.php,
                 * which adds the same columns to an installation that already exists.
                 */
                $table->string( 'purchase_order_number' )->nullable();
                $table->string( 'delivery_note_number' )->nullable();
                $table->date( 'delivery_note_date' )->nullable();
                $table->string( 'billing_note_number' )->nullable();
                $table->date( 'billing_note_date' )->nullable();
                $table->string( 'contact_person' )->nullable();
                $table->unsignedInteger( 'credit_days' )->nullable();
                $table->date( 'due_date' )->nullable();
                $table->boolean( 'automatic_approval' )->default( false )->nullable();
                $table->datetime( 'delivery_time' )->nullable();
                $table->datetime( 'invoice_date' )->nullable();
                $table->string( 'payment_status' )->default( 'unpaid' ); // paid | unpaid
                $table->string( 'delivery_status' )->default( 'pending' ); // pending, delivered, stocked
                $table->integer( 'total_items' )->default( 0 );

                /**
                 * The purchasing workflow's columns: which branch received the goods, which
                 * purchase order the receipt belongs to, whether accounts has approved it for
                 * billing, how much credit a return has earned against it, and whether it was a
                 * trial document that has since been archived.
                 *
                 * They are declared here because the migrations that add them live at the root of
                 * database/migrations, and the root set runs before this file does — so on a fresh
                 * installation they are skipped. Each of those files now carries a hasTable guard
                 * and a note pointing here. An installation that already exists gets them from
                 * there; a fresh one gets them from here.
                 */
                $table->unsignedBigInteger( 'branch_id' )->nullable()->index();
                $table->unsignedBigInteger( 'purchase_order_id' )->nullable()->index();
                $table->string( 'billing_approval_status', 20 )->nullable();
                $table->unsignedBigInteger( 'billing_approved_by' )->nullable();
                $table->timestamp( 'billing_approved_at' )->nullable();
                $table->decimal( 'purchase_return_credit', 20, 5 )->default( 0 );
                $table->timestamp( 'trial_archived_at' )->nullable();
                $table->unsignedBigInteger( 'trial_archived_by' )->nullable();
                $table->text( 'description' )->nullable();
                $table->integer( 'author_id' );
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
        if ( Schema::hasTable( 'nexopos_procurements' ) ) {
            Schema::dropIfExists( 'nexopos_procurements' );
        }
    }
};
