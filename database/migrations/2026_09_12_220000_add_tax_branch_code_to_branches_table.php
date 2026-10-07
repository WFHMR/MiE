<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The VAT establishment code of each branch.
 *
 * Thai VAT gives every establishment its own five digit code — 00000 for the head office, 00001
 * onwards for each branch — and a tax invoice has to show the code of the establishment that issued
 * it. The code was read from a single option shared by the whole install, so a sale rung up at a
 * branch printed the head office code. That is a defect on a tax record, not a cosmetic one, and it
 * gets worse with every branch opened.
 *
 * Nullable on purpose: a branch that has not been given a code falls back to the install wide
 * option, which is exactly the behaviour before this column existed, so nothing changes until a code
 * is filled in.
 *
 * `code` already on the table is the shop's own label (MAIN, THACHANG) and is not the VAT code, so it
 * cannot be reused for this.
 *
 * Placed in this directory rather than migrations/update because SetupService marks everything under
 * update as executed *without running it* — a column added there would never exist on a fresh
 * install. This is the same directory the branches table itself and is_main_warehouse were added in.
 */
return new class extends Migration
{
    private const TABLE = 'nexopos_branches';

    public function up(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( Schema::hasColumn( self::TABLE, 'tax_branch_code' ) ) {
            return;
        }

        Schema::table( self::TABLE, function ( Blueprint $table ) {
            $table->string( 'tax_branch_code', 5 )->nullable()->after( 'code' );
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        if ( ! Schema::hasColumn( self::TABLE, 'tax_branch_code' ) ) {
            return;
        }

        Schema::table( self::TABLE, function ( Blueprint $table ) {
            $table->dropColumn( 'tax_branch_code' );
        } );
    }
};
