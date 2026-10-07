<?php

/**
 * Table Migration
 *
 * Thai withholding tax records (ภาษีหัก ณ ที่จ่าย) and the sequences that number the
 * certificates.
 *
 * When a business pays for a service it must withhold a slice of the payment and remit
 * it to the Revenue Department, give the payee a certificate (หนังสือรับรองการหักภาษี
 * ณ ที่จ่าย, form 50 ทวิ), and report the month's withholdings on ภ.ง.ด.3 for payees
 * who are individuals or ภ.ง.ด.53 for payees who are companies.
 *
 * Two things drive the shape of this table:
 *
 *  - The tax is computed on the payment **before VAT**. Withholding on the VAT
 *    inclusive amount over-withholds and the payee is short-paid, so the base is
 *    stored explicitly rather than derived from a gross figure at print time.
 *  - The certificate is a document the payee files with their own return. Once it has
 *    been handed over, nothing about it may change — so the payee's name, taxpayer
 *    number and address are copied onto the record rather than referenced.
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
        if ( ! Schema::hasTable( 'nexopos_withholding_tax_sequences' ) ) {
            Schema::createIfMissing( 'nexopos_withholding_tax_sequences', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );

                /**
                 * One running series, for example "WHT-2026". A yearly reset is a new scope
                 * rather than a counter being rewound.
                 */
                $table->string( 'scope' )->unique();
                $table->unsignedBigInteger( 'last_number' )->default( 0 );
                $table->timestamps();
            } );
        }

        if ( ! Schema::hasTable( 'nexopos_withholding_taxes' ) ) {
            Schema::createIfMissing( 'nexopos_withholding_taxes', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );
                $table->string( 'uuid' )->nullable();

                /**
                 * The certificate number and the series it came from, unique per series.
                 */
                $table->string( 'sequence_scope' );
                $table->unsignedBigInteger( 'sequence_number' );
                $table->string( 'number' );

                /**
                 * Which monthly return this belongs on. Derived from the payee type rather
                 * than chosen: an individual goes on ภ.ง.ด.3 and a company on ภ.ง.ด.53,
                 * and letting the two be picked independently is how a filing gets rejected.
                 */
                $table->string( 'form' );        // pnd3, pnd53
                $table->string( 'payee_type' );  // individual, juristic

                /**
                 * The payee as they were at the moment of payment. Snapshotted: a landlord
                 * who moves must not change a certificate already filed.
                 */
                $table->string( 'payee_name' );
                $table->string( 'payee_tax_id' );
                $table->string( 'payee_branch_code' )->default( '00000' );
                $table->text( 'payee_address' )->nullable();

                /**
                 * The Revenue Code section 40 category, which is a column on both forms,
                 * plus what the payment was actually for in words.
                 */
                $table->string( 'income_type' );
                $table->string( 'income_description' )->nullable();

                /**
                 * base_amount is the payment excluding VAT — the figure the rate applies
                 * to. gross_amount is what the payee invoiced including VAT, kept so the
                 * bookkeeper can tie the certificate back to the supplier's invoice.
                 */
                $table->decimal( 'base_amount', 18, 5 )->default( 0 );
                $table->decimal( 'gross_amount', 18, 5 )->default( 0 );
                $table->decimal( 'rate', 8, 4 )->default( 0 );
                $table->decimal( 'tax_amount', 18, 5 )->default( 0 );

                /**
                 * The date the payment was made, which is what determines the filing month.
                 * Separate from created_at: a payment can be recorded days later.
                 */
                $table->date( 'paid_on' );

                /**
                 * The expense this withholding came out of, when the shop recorded one.
                 * Nullable on purpose — a shop may keep its expenses elsewhere and still
                 * need to issue certificates and file the monthly return.
                 */
                $table->unsignedBigInteger( 'transaction_id' )->nullable();

                /**
                 * A certificate is never deleted or renumbered. It is cancelled, and the
                 * number stays consumed.
                 */
                $table->string( 'status' )->default( 'issued' ); // issued, cancelled
                $table->text( 'cancellation_reason' )->nullable();
                $table->dateTime( 'cancelled_at' )->nullable();

                $table->integer( 'author_id' )->nullable();
                $table->timestamps();

                /**
                 * Named explicitly because the generated name would be
                 * "<prefix>nexopos_withholding_taxes_sequence_scope_sequence_number_unique",
                 * which is 66 characters before any table prefix is added. MySQL rejects an
                 * identifier over 64, so the install failed outright on MySQL while SQLite,
                 * which has no such limit, accepted it and hid the problem.
                 */
                $table->unique( [ 'sequence_scope', 'sequence_number' ], 'nexopos_wht_sequence_unique' );
                $table->index( 'paid_on' );
                $table->index( 'form' );
                $table->index( 'payee_tax_id' );
                $table->index( 'transaction_id' );
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
        if ( Schema::hasTable( 'nexopos_withholding_taxes' ) ) {
            Schema::dropIfExists( 'nexopos_withholding_taxes' );
        }

        if ( Schema::hasTable( 'nexopos_withholding_tax_sequences' ) ) {
            Schema::dropIfExists( 'nexopos_withholding_tax_sequences' );
        }
    }
};
