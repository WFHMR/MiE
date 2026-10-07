<?php

/**
 * Table Migration
 * Adds provider_id to products: the supplier the shop normally buys this item from.
 *
 * Deliberately not the same thing as the supplier on a purchase batch. The batch records who a
 * particular delivery was actually bought from, which is a fact about money that changed hands. This
 * column records a habit. The sold stock report reads the batch first and falls back to this, and states
 * on every row which of the two it used, so nobody closing the books mistakes the habit for the fact.
 *
 * Nullable because it has to be. Thousands of products already exist and the shop is trading; a product
 * with nothing here reports as unattributed, exactly as it did before this column existed.
 *
 * Uses the framework's Schema facade rather than App\Classes\Schema. The wrapper pushes every table name
 * through the ns-table-name hook, and a guard that asks about the wrong name returns false, skips the
 * body, and still records itself as run — a silent no-op that is only discovered when something reads the
 * column and finds it missing.
 *
 * Kept in step with database/migrations/create/2020_06_20_000000_create_products_table.php,
 * which carries the same column for a fresh installation.
 **/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'nexopos_products';

    public function up(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( ! Schema::hasColumn( self::TABLE, 'provider_id' ) ) {
            Schema::table( self::TABLE, function ( Blueprint $table ): void {
                $table->integer( 'provider_id' )->nullable()->after( 'category_id' );
                $table->index( 'provider_id', 'idx_products_provider' );
            } );
        }
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( Schema::hasColumn( self::TABLE, 'provider_id' ) ) {
            Schema::table( self::TABLE, function ( Blueprint $table ): void {
                $table->dropIndex( 'idx_products_provider' );
                $table->dropColumn( 'provider_id' );
            } );
        }
    }
};
