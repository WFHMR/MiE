<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The supplier code the shop actually trades by, for an installation that already exists.
 *
 * Every supplier in the shop's catalogue carries a code of the form SP520099, and the staff quote
 * that code rather than the company name — the names run long and several read almost alike, for
 * example "บริษัท พรรณงาม 1999 จำกัด" against "บริษัท พรรณงาม จำกัด". The table had nowhere to keep it, so
 * the importer dropped it: it read the company name column and skipped the code column beside it,
 * then pasted the code into the *product* description where nothing can search or sort by it. The
 * supplier list therefore showed 514 rows of long Thai names with no code on screen anywhere.
 *
 * Unique, because the catalogue proves the code identifies one supplier: across 50,132 rows carrying
 * a code there are 726 distinct codes and 726 distinct names, with no code pointing at two names and
 * no name carrying two codes. The index turns a mistyped duplicate into a refusal instead of two
 * suppliers that look like one.
 *
 * Nullable, because 9 of the 514 suppliers on the shop's database were added by hand and appear
 * nowhere in the catalogue. MySQL permits repeated NULLs under a unique index, which is what lets
 * those rows stay as they are rather than forcing an invented code.
 *
 * This lives in migrations/update and not at the root of migrations/. The root set is run by
 * SetupService *before* the create set, and nexopos_providers is created by
 * migrations/create/2020_06_20_000000_create_providers_table.php — so a copy of this placed at the
 * root found no table, returned early, and was still recorded as executed. The column then existed
 * nowhere while the migration looked done. The create file carries the column for a fresh install;
 * this file carries it for an installation that already has the table.
 */
return new class extends Migration
{
    private const TABLE = 'nexopos_providers';

    public function up(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( Schema::hasColumn( self::TABLE, 'code' ) ) {
            return;
        }

        Schema::table( self::TABLE, function ( Blueprint $table ) {
            $table->string( 'code', 50 )->nullable()->unique()->after( 'id' );
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( ! Schema::hasColumn( self::TABLE, 'code' ) ) {
            return;
        }

        Schema::table( self::TABLE, function ( Blueprint $table ) {
            /**
             * The index is dropped by name rather than by column: dropping the column alone leaves
             * the unique index behind on MySQL and a later re-run of up() then fails.
             */
            $table->dropUnique( 'nexopos_providers_code_unique' );
            $table->dropColumn( 'code' );
        } );
    }
};
