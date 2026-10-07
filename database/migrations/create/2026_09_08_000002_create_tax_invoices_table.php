<?php

/**
 * Table Migration
 *
 * Thai tax invoices (ใบกำกับภาษี) and the sequences that number them.
 *
 * A tax invoice is a legal document, not a view over an order. Section 86/4 and
 * 86/6 of the Revenue Code require a sequential number, the seller's registration
 * details, and the VAT shown separately — and once a document has been reported on
 * a PP30 return it must reproduce identically forever, even if the product is later
 * reclassified or the shop's address changes. So the document keeps its own copy of
 * everything it prints.
 *
 * Order::code cannot serve as the number: it is a per-day counter that resets at
 * midnight, is allocated before the order is persisted (so failures burn numbers),
 * and is padded to three digits.
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
        if ( ! Schema::hasTable( 'nexopos_tax_invoice_sequences' ) ) {
            Schema::createIfMissing( 'nexopos_tax_invoice_sequences', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );

                /**
                 * Identifies one running series, for example "TIV-2026". Numbers are
                 * allocated per scope so a yearly reset is a new scope rather than a
                 * rewrite of the counter.
                 */
                $table->string( 'scope' )->unique();
                $table->unsignedBigInteger( 'last_number' )->default( 0 );
                $table->timestamps();
            } );
        }

        if ( ! Schema::hasTable( 'nexopos_tax_invoices' ) ) {
            Schema::createIfMissing( 'nexopos_tax_invoices', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );
                $table->string( 'uuid' )->nullable();

                /**
                 * abbreviated = ใบกำกับภาษีอย่างย่อ (Section 86/6, retail counter)
                 * full        = ใบกำกับภาษีแบบเต็มรูป (Section 86/4, buyer claims input tax)
                 */
                $table->string( 'type' );

                /**
                 * The printed number and the series it came from. Unique per series so a
                 * duplicate can never be issued, whatever races upstream.
                 */
                $table->string( 'sequence_scope' );
                $table->unsignedBigInteger( 'sequence_number' );
                $table->string( 'number' );

                $table->unsignedBigInteger( 'order_id' );
                $table->dateTime( 'issued_at' );

                /**
                 * Seller registration as it stood when the document was issued. Reprinting
                 * must not pick up a later change of address or branch.
                 */
                $table->string( 'seller_tax_id' );
                $table->string( 'seller_name' );
                $table->text( 'seller_address' )->nullable();
                $table->string( 'seller_branch_code' )->default( '00000' );

                /**
                 * Buyer details. Required on a full form invoice, absent on an abbreviated
                 * one — the counter does not ask a walk-in customer for an address.
                 */
                $table->string( 'buyer_name' )->nullable();
                $table->text( 'buyer_address' )->nullable();
                $table->string( 'buyer_tax_id' )->nullable();

                /**
                 * The remaining fields the printed A4 form carries. Frozen here for the
                 * same reason as everything else on this table: the customer's phone, their
                 * credit terms and the shop's phone number can all change after a document
                 * has been handed over, and a reprint has to match what they received.
                 *
                 * Kept in step with
                 * database/migrations/update/2026_09_09_120000_add_document_header_fields_to_tax_invoices.php,
                 * which adds the same columns to a database that already exists.
                 */
                $table->string( 'buyer_code' )->nullable();
                $table->string( 'buyer_phone' )->nullable();
                $table->string( 'buyer_branch' )->nullable();
                $table->string( 'seller_phone' )->nullable();
                $table->string( 'payment_terms' )->nullable();
                $table->date( 'due_date' )->nullable();

                /**
                 * The rate in force on the day of the sale, and the split of the sale
                 * across the three VAT classifications. Stored rather than recomputed so
                 * the figures on the document and on the PP30 return can never drift.
                 */
                $table->decimal( 'vat_rate', 8, 4 )->default( 0 );
                $table->decimal( 'total_net', 18, 5 )->default( 0 );
                $table->decimal( 'total_vat', 18, 5 )->default( 0 );
                $table->decimal( 'total_gross', 18, 5 )->default( 0 );
                $table->decimal( 'total_exempt', 18, 5 )->default( 0 );
                $table->decimal( 'total_zero_rated', 18, 5 )->default( 0 );

                /**
                 * A frozen copy of the printed lines. An order can still be edited after
                 * the fact; the document it produced cannot.
                 */
                $table->json( 'lines' )->nullable();

                /**
                 * A tax invoice is never deleted or renumbered. It is cancelled, and a
                 * replacement points back at what it replaces.
                 */
                $table->string( 'status' )->default( 'issued' ); // issued, cancelled
                $table->text( 'cancellation_reason' )->nullable();
                $table->dateTime( 'cancelled_at' )->nullable();
                $table->unsignedBigInteger( 'replaces_id' )->nullable();

                $table->integer( 'author_id' )->nullable();
                $table->timestamps();

                /**
                 * Named explicitly for the same reason as the withholding table: the
                 * generated name is 61 characters and MySQL's limit is 64, so any table
                 * prefix longer than three characters would push it over and fail the
                 * install.
                 */
                $table->unique( [ 'sequence_scope', 'sequence_number' ], 'nexopos_tax_invoice_sequence_unique' );
                $table->index( 'order_id' );
                $table->index( 'issued_at' );
                $table->index( 'type' );
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
        if ( Schema::hasTable( 'nexopos_tax_invoices' ) ) {
            Schema::dropIfExists( 'nexopos_tax_invoices' );
        }

        if ( Schema::hasTable( 'nexopos_tax_invoice_sequences' ) ) {
            Schema::dropIfExists( 'nexopos_tax_invoice_sequences' );
        }
    }
};
