<?php

/**
 * Table Migration
 * Adds the branch columns that the multi branch feature needs on users, orders and stock history,
 * plus the till a user is tied to.
 *
 * Migrations for these already exist in the root database/migrations folder, but that folder runs
 * before NexoPOS builds its own tables during an installation. Their guards find no table yet, skip
 * without error, and the columns end up recorded as added while being absent. Confirmed against a
 * freshly built database: nexopos_branches and nexopos_stock_transfers, which create their own
 * tables, were present, while every branch_id added to a NexoPOS table was missing.
 *
 * The effect on a fresh installation is that the whole multi branch feature stops working: tills
 * cannot be assigned, sales cannot be attributed, and stock movements cannot be told apart. The
 * installation in use is unaffected because the columns were applied there by hand.
 *
 * Matching columns have been added to the create and core migrations so a fresh installation gets
 * them from the start. Both guards simply pass over an installation that already has them.
 **/

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Table to the columns it should carry.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'nexopos_users' => [ 'branch_id', 'assigned_register_id' ],
        'nexopos_orders' => [ 'branch_id' ],
        'nexopos_products_histories' => [ 'branch_id' ],
    ];

    public function up(): void
    {
        foreach ( self::COLUMNS as $table => $columns ) {
            if ( ! Schema::hasTable( $table ) ) {
                continue;
            }

            $missing = array_values( array_filter(
                $columns,
                fn ( string $column ): bool => ! Schema::hasColumn( $table, $column )
            ) );

            if ( $missing === [] ) {
                continue;
            }

            Schema::table( $table, function ( Blueprint $blueprint ) use ( $missing ): void {
                foreach ( $missing as $column ) {
                    $blueprint->integer( $column )->nullable();
                    $blueprint->index( $column );
                }
            } );
        }
    }

    public function down(): void
    {
        foreach ( self::COLUMNS as $table => $columns ) {
            if ( ! Schema::hasTable( $table ) ) {
                continue;
            }

            $present = array_values( array_filter(
                $columns,
                fn ( string $column ): bool => Schema::hasColumn( $table, $column )
            ) );

            if ( $present === [] ) {
                continue;
            }

            Schema::table( $table, function ( Blueprint $blueprint ) use ( $present ): void {
                foreach ( $present as $column ) {
                    $blueprint->dropIndex( [ $column ] );
                    $blueprint->dropColumn( $column );
                }
            } );
        }
    }
};
