<?php

/**
 * Table Migration
 * Adds branch_id to products_unit_quantities so stock and price are held per branch.
 *
 * A migration adding this column already exists in the root database/migrations folder, but
 * that folder runs before NexoPOS builds its own tables during an installation. The guard
 * there finds no products_unit_quantities table yet, skips without error, and the column is
 * recorded as added while being absent. Verified on a freshly built database: branches and
 * stock_transfers, which create their own tables, are present, while every branch_id added to
 * a NexoPOS table is missing.
 *
 * This copy lives in update/ where the installer reaches it, and the matching column has been
 * added to create/2020_06_20_000000_create_products_unit_quantities.php for fresh installs.
 * On an installation that already carries the column both guards simply pass over it.
 *
 * The remaining branch_id columns on users, orders, products_histories and registers have the
 * same defect and are deliberately left alone here: they are not needed by the work this
 * migration accompanies, and moving them deserves its own change.
 **/

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    private const TABLE = 'nexopos_products_unit_quantities';

    public function up(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( Schema::hasColumn( self::TABLE, 'branch_id' ) ) {
            return;
        }

        Schema::table( self::TABLE, function ( Blueprint $table ): void {
            $table->integer( 'branch_id' )->nullable()->after( 'id' );
            $table->index( [ 'branch_id', 'product_id' ] );
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( ! Schema::hasColumn( self::TABLE, 'branch_id' ) ) {
            return;
        }

        Schema::table( self::TABLE, function ( Blueprint $table ): void {
            $table->dropIndex( [ 'branch_id', 'product_id' ] );
            $table->dropColumn( 'branch_id' );
        } );
    }
};
