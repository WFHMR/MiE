<?php

/**
 * Table Migration
 *
 * ใบแจ้งหนี้/วางบิลค่าสินค้า (ชั่วคราว) — the provisional billing note.
 *
 * A credit customer does not settle each delivery as it arrives. The shop's runner
 * collects the delivery notes that have fallen due for one customer, lists them on a
 * single sheet, and leaves it with the customer's accounts desk to be checked against
 * their own records. The customer signs it, keeps a copy, and pays later against it.
 *
 * "ชั่วคราว" is on the printed form for a reason: this is not a tax document and it
 * does not create or discharge a liability. The tax invoices it lists already did that.
 * So there is no VAT snapshot here and no Revenue Code requirement — but the customer
 * is holding a copy with figures on it, and those figures have to still be there when
 * the payment is reconciled weeks later. Hence the frozen snapshot, the same as the tax
 * invoice table.
 *
 * The lines are documents, not products. One row is one delivery note that is being
 * asked for, which is why the amounts here are per-document totals and why the table
 * has no product columns.
 *
 * Lives at the root of database/migrations rather than under create/ because that is
 * where this project's own additions go and it is what deploy/install-app.sh runs
 * through "php artisan migrate --force". Creating its own tables makes it safe there:
 * nothing it touches has to exist first.
 *
 * @since v6.3.1
 **/

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if ( ! Schema::hasTable( 'nexopos_billing_note_sequences' ) ) {
            Schema::createIfMissing( 'nexopos_billing_note_sequences', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );

                /**
                 * One running series, for example "BN-2026". Its own table rather than a
                 * scope inside the tax invoice counter: the two series are numbered
                 * independently, reset independently, and a shop that renumbers its
                 * billing notes must not disturb a legally sequential tax invoice series.
                 */
                $table->string( 'scope' )->unique();
                $table->unsignedBigInteger( 'last_number' )->default( 0 );
                $table->timestamps();
            } );
        }

        if ( ! Schema::hasTable( 'nexopos_billing_notes' ) ) {
            Schema::createIfMissing( 'nexopos_billing_notes', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );
                $table->string( 'uuid' )->nullable();

                $table->string( 'sequence_scope' );
                $table->unsignedBigInteger( 'sequence_number' );
                $table->string( 'number' );

                $table->unsignedBigInteger( 'customer_id' )->nullable();
                $table->dateTime( 'issued_at' );

                /**
                 * นัดชำระวันที่ — the date the customer's accounts desk undertakes to pay.
                 * Blank on the printed form and filled in by hand at the counter, so it is
                 * nullable and editable after issue. It is the one field on this document
                 * that is negotiated rather than recorded.
                 */
                $table->date( 'appointment_date' )->nullable();

                /**
                 * The shop's letterhead as it stood on the day. No tax id: this is not a
                 * tax document and the printed form does not carry one.
                 */
                $table->string( 'seller_name' );
                $table->text( 'seller_address' )->nullable();
                $table->string( 'seller_phone' )->nullable();
                $table->string( 'seller_fax' )->nullable();

                /**
                 * The customer as addressed. Frozen because a customer can be renamed or
                 * moved, and the copy in their hands says what it said.
                 */
                $table->string( 'customer_name' );
                $table->text( 'customer_address' )->nullable();
                $table->string( 'customer_code' )->nullable();

                /**
                 * One entry per delivery note being billed: its number, its date, the
                 * credit term it was sold on, the date it fell due and its total.
                 */
                $table->json( 'lines' )->nullable();

                /**
                 * total_billed is the sum of the listed documents. total_deducted is
                 * หักตามหมายเหตุ — what the customer's desk strikes off during the check,
                 * for a short delivery or a credit already agreed. total_net is what the
                 * customer accepts, and it is the figure the payment is matched against,
                 * so it is stored rather than derived: a later edit to the deduction must
                 * not silently restate a note that has already been accepted.
                 */
                $table->decimal( 'total_billed', 18, 5 )->default( 0 );
                $table->decimal( 'total_deducted', 18, 5 )->default( 0 );
                $table->decimal( 'total_net', 18, 5 )->default( 0 );

                /**
                 * หมายเหตุ — the free text block under the totals, where the reason for a
                 * deduction is written out.
                 */
                $table->text( 'notes' )->nullable();

                $table->string( 'status' )->default( 'issued' ); // issued, cancelled
                $table->text( 'cancellation_reason' )->nullable();
                $table->dateTime( 'cancelled_at' )->nullable();

                $table->integer( 'author_id' )->nullable();
                $table->timestamps();

                /**
                 * Named explicitly. The generated name would run to 62 characters against
                 * MySQL's 64 limit, so any table prefix beyond three characters would push
                 * it over and fail the install — the same trap the tax invoice table hit.
                 */
                $table->unique( [ 'sequence_scope', 'sequence_number' ], 'nexopos_billing_note_sequence_unique' );
                $table->index( 'customer_id' );
                $table->index( 'issued_at' );
            } );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists( 'nexopos_billing_notes' );
        Schema::dropIfExists( 'nexopos_billing_note_sequences' );
    }
};
