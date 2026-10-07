<?php

/**
 * Table Migration
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
        if ( ! Schema::hasTable( 'nexopos_providers' ) ) {
            Schema::createIfMissing( 'nexopos_providers', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );
                /**
                 * The shop's own supplier code, for example SP520099, which is what the staff quote
                 * instead of the company name. Nullable because a supplier entered by hand may not
                 * have one; unique because the code identifies exactly one supplier.
                 *
                 * Kept in step with database/migrations/update/2026_09_18_090000_add_code_to_providers_table.php,
                 * which adds the same column to an installation that already exists.
                 */
                $table->string( 'code', 50 )
                    ->unique()
                    ->nullable();
                $table->string( 'first_name' );
                $table->string( 'last_name' )->nullable();
                $table->string( 'email' )
                    ->unique()
                    ->nullable();
                $table->string( 'phone' )->nullable();
                $table->string( 'address_1' )->nullable();
                $table->string( 'address_2' )->nullable();
                $table->integer( 'author_id' );
                $table->text( 'description' )->nullable();
                $table->decimal( 'amount_due', 18, 5 )->default( 0 );
                $table->decimal( 'amount_paid', 18, 5 )->default( 0 );
                $table->string( 'uuid' )->nullable();
                $table->timestamps();
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
        if ( Schema::hasTable( 'nexopos_providers' ) ) {
            Schema::dropIfExists( 'nexopos_providers' );
        }
    }
};
