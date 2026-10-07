<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Determine whether the migration
     * should execute when we're accessing
     * a multistore instance.
     */
    public function runOnMultiStore()
    {
        return false;
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if ( ! Schema::hasTable( 'nexopos_users' ) ) {
            Schema::create( 'nexopos_users', function ( Blueprint $table ) {
                $table->increments( 'id' );
                $table->string( 'username' );
                $table->boolean( 'active' )->default( false );
                $table->integer( 'author_id' )->nullable(); // the first user is created by him self
                $table->string( 'email' )->unique();
                $table->string( 'password' );
                $table->integer( 'group_id' )->nullable();
                $table->string( 'first_name' )->nullable();
                $table->string( 'last_name' )->nullable();
                $table->string( 'gender' )->nullable();
                $table->string( 'phone' )->nullable();
                $table->string( 'pobox' )->nullable();
                $table->datetime( 'activation_expiration' )->nullable();
                $table->integer( 'total_sales_count' )->default( 0 );
                $table->float( 'total_sales', 18, 5 )->default( 0 );
                $table->datetime( 'birth_date' )->nullable();
                $table->float( 'purchases_amount' )->default( 0 );
                $table->float( 'owed_amount' )->default( 0 );
                $table->float( 'credit_limit_amount' )->default( 0 );
                $table->float( 'account_amount' )->default( 0 );
                $table->string( 'activation_token' )->nullable();

                /**
                 * Which branch the person works at, and which till they are tied to. A branch with
                 * several checkout stations needs each station on its own drawer, otherwise the
                 * cash count at the end of a shift no longer matches. Both are null for anyone
                 * unrestricted, such as a supervisor or the administrator. — @since v6.3.0
                 */
                $table->integer( 'branch_id' )->nullable();
                $table->integer( 'assigned_register_id' )->nullable();

                $table->rememberToken();
                $table->timestamps();

                $table->index( 'branch_id' );
                $table->index( 'assigned_register_id' );
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
        Schema::dropIfExists( 'nexopos_users' );
    }
};
