<?php

/**
 * Adds the header fields the printed Thai delivery note / tax invoice / receipt form
 * carries but the original table did not store.
 *
 * The paper form prints a buyer reference code, the buyer's phone and establishment,
 * the seller's phone line, the payment terms and the due date. None of those could be
 * filled from the existing columns, and reading them from the live order at print time
 * would break the one rule this document has: a reprint must reproduce what the
 * customer was handed, and the customer record, their credit terms and the shop's phone
 * number can all change afterwards.
 *
 * Nullable throughout, so documents issued before this migration keep printing with
 * those lines blank rather than failing.
 *
 * Mirrors the additions made to database/migrations/create/2026_09_08_000002. That
 * duplication is deliberate, and so is this file's location.
 *
 * NexoPOS keeps its own schema under database/migrations/{create,core,update} and runs
 * those through UpdateService, not through "php artisan migrate". A fresh install gets
 * these columns from the create migration. An install that already exists gets them from
 * here, because the root of database/migrations is the only place plain "artisan migrate"
 * looks, and that is what a deploy runs.
 *
 * The hasTable guard is what makes living in the root safe: on a fresh install this runs
 * before NexoPOS has created any of its tables, finds nothing, and does nothing — the
 * create migration has already covered that case. Putting schema for an existing table in
 * the root without such a guard is how the branch_id columns came to be silently skipped
 * on every fresh install.
 *
 * @since v6.3.1
 **/

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const STRING_COLUMNS = [
        'buyer_code',
        'buyer_phone',
        'buyer_branch',
        'seller_phone',
        'payment_terms',
    ];

    public function up(): void
    {
        if ( ! Schema::hasTable( 'nexopos_tax_invoices' ) ) {
            return;
        }

        Schema::table( 'nexopos_tax_invoices', function ( Blueprint $table ) {
            foreach ( self::STRING_COLUMNS as $column ) {
                if ( ! Schema::hasColumn( 'nexopos_tax_invoices', $column ) ) {
                    $table->string( $column )->nullable();
                }
            }

            if ( ! Schema::hasColumn( 'nexopos_tax_invoices', 'due_date' ) ) {
                $table->date( 'due_date' )->nullable();
            }
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'nexopos_tax_invoices' ) ) {
            return;
        }

        Schema::table( 'nexopos_tax_invoices', function ( Blueprint $table ) {
            foreach ( array_merge( self::STRING_COLUMNS, [ 'due_date' ] ) as $column ) {
                if ( Schema::hasColumn( 'nexopos_tax_invoices', $column ) ) {
                    $table->dropColumn( $column );
                }
            }
        } );
    }
};
