<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The paperwork a purchase actually travels with.
 *
 * A purchase order in this system carried only its own name, an invoice reference and two dates.
 * That is not enough for either desk that handles the purchase after it is raised:
 *
 *   - **Receiving** checks the goods that arrive against the supplier's delivery note — price and
 *     quantity, line by line. Without the delivery note number on the record there is nothing to
 *     check against, so the check happens on paper and never reaches the system.
 *   - **Accounts** pays against the supplier's billing note, and has to know which receipt that
 *     billing note belongs to. Without it the payment gets filed against the wrong purchase, which
 *     is the failure the shop described: "ไม่งั้นเขาจะนำไปวางบิลผิด".
 *
 * Every column is nullable. A purchase is raised before any of this paperwork exists — the delivery
 * note arrives with the goods and the billing note later still — so a record must be saveable with
 * all of them empty and filled in as the documents turn up. Making any of them required would also
 * break ProcurementListVisibilityTest, which builds Procurement rows column by column.
 *
 * `credit_days` is unsigned: a negative credit term is not a thing. `due_date` is stored rather than
 * derived on read so that a term agreed verbally, differing from days × invoice date, survives.
 * ProcurementService fills it from the invoice date plus the credit days when it is left blank.
 *
 * This lives in migrations/update and not at the root of migrations/. SetupService runs the root set
 * *before* the create set, and nexopos_procurements is created by
 * migrations/create/2020_06_20_000000_create_procurements_table.php — so a copy of this placed at the
 * root would find no table, return early, and still be recorded as executed, leaving the columns
 * nowhere while the migration looked done. That is exactly what happened with the supplier code
 * column; see PENDING-WORK-RESUME.md §41.3. The create file carries these columns for a fresh
 * install, this file carries them for an installation that already has the table.
 */
return new class extends Migration
{
    private const TABLE = 'nexopos_procurements';

    /**
     * @var array<string, string> column => type
     */
    private const COLUMNS = [
        'purchase_order_number' => 'string',
        'delivery_note_number' => 'string',
        'delivery_note_date' => 'date',
        'billing_note_number' => 'string',
        'billing_note_date' => 'date',
        'contact_person' => 'string',
        'credit_days' => 'unsignedInteger',
        'due_date' => 'date',
    ];

    public function up(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        $missing = array_filter(
            self::COLUMNS,
            fn ( string $type, string $column ): bool => ! Schema::hasColumn( self::TABLE, $column ),
            ARRAY_FILTER_USE_BOTH
        );

        if ( $missing === [] ) {
            return;
        }

        Schema::table( self::TABLE, function ( Blueprint $table ) use ( $missing ) {
            foreach ( $missing as $column => $type ) {
                $table->{$type}( $column )->nullable();
            }
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        $present = array_keys( array_filter(
            self::COLUMNS,
            fn ( string $type, string $column ): bool => Schema::hasColumn( self::TABLE, $column ),
            ARRAY_FILTER_USE_BOTH
        ) );

        if ( $present === [] ) {
            return;
        }

        Schema::table( self::TABLE, function ( Blueprint $table ) use ( $present ) {
            $table->dropColumn( $present );
        } );
    }
};
