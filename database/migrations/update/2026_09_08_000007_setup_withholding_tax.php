<?php

/**
 * Table Migration
 *
 * Brings Thai withholding tax to an installation that already exists: the tables, the
 * permissions, and the grants.
 *
 * The tables are declared in a "create" migration for new installations, but that file
 * only runs during setup — a shop installed before this release would have the routes and
 * the menu without anything behind them. The permissions have the same problem: the core
 * permission migration includes database/permissions/withholding-tax.php, and it runs
 * once.
 *
 * Granting is separate from creating because Permission::includes( '.taxes' ) in
 * admin-role.php does not match ".withholding-tax", so the roles have to be told.
 *
 * @since v6.3.0
 **/

use App\Classes\Schema;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Issuing and cancelling both land on a store administrator. A cashier has no reason
     * to be near a document that changes what the shop remits to the Revenue Department.
     *
     * @var array<int, string>
     */
    private array $administrativeRoles = [
        Role::ADMIN,
        Role::STOREADMIN,
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->createTables();
        $this->createPermissions();
    }

    private function createTables(): void
    {
        if ( ! Schema::hasTable( 'nexopos_withholding_tax_sequences' ) ) {
            Schema::createIfMissing( 'nexopos_withholding_tax_sequences', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );
                $table->string( 'scope' )->unique();
                $table->unsignedBigInteger( 'last_number' )->default( 0 );
                $table->timestamps();
            } );
        }

        if ( ! Schema::hasTable( 'nexopos_withholding_taxes' ) ) {
            Schema::createIfMissing( 'nexopos_withholding_taxes', function ( Blueprint $table ) {
                $table->bigIncrements( 'id' );
                $table->string( 'uuid' )->nullable();

                $table->string( 'sequence_scope' );
                $table->unsignedBigInteger( 'sequence_number' );
                $table->string( 'number' );

                $table->string( 'form' );        // pnd3, pnd53
                $table->string( 'payee_type' );  // individual, juristic

                $table->string( 'payee_name' );
                $table->string( 'payee_tax_id' );
                $table->string( 'payee_branch_code' )->default( '00000' );
                $table->text( 'payee_address' )->nullable();

                $table->string( 'income_type' );
                $table->string( 'income_description' )->nullable();

                $table->decimal( 'base_amount', 18, 5 )->default( 0 );
                $table->decimal( 'gross_amount', 18, 5 )->default( 0 );
                $table->decimal( 'rate', 8, 4 )->default( 0 );
                $table->decimal( 'tax_amount', 18, 5 )->default( 0 );

                $table->date( 'paid_on' );
                $table->unsignedBigInteger( 'transaction_id' )->nullable();

                $table->string( 'status' )->default( 'issued' );
                $table->text( 'cancellation_reason' )->nullable();
                $table->dateTime( 'cancelled_at' )->nullable();

                $table->integer( 'author_id' )->nullable();
                $table->timestamps();

                /**
                 * Named explicitly: the generated name is 66 characters before any table
                 * prefix, and MySQL rejects an identifier over 64. Kept identical to the
                 * "create" migration so both paths produce the same schema.
                 */
                $table->unique( [ 'sequence_scope', 'sequence_number' ], 'nexopos_wht_sequence_unique' );
                $table->index( 'paid_on' );
                $table->index( 'form' );
                $table->index( 'payee_tax_id' );
                $table->index( 'transaction_id' );
            } );
        }
    }

    private function createPermissions(): void
    {
        /**
         * The permission file is inert unless this is defined, which is how it stays quiet
         * when something other than a migration pulls it in.
         */
        if ( ! defined( 'NEXO_CREATE_PERMISSIONS' ) ) {
            define( 'NEXO_CREATE_PERMISSIONS', true );
        }

        include_once dirname( __FILE__ ) . '/../../permissions/withholding-tax.php';

        $namespaces = Permission::includes( '.withholding-tax' )
            ->get()
            ->map( fn( $permission ) => $permission->namespace )
            ->unique()
            ->values()
            ->toArray();

        if ( empty( $namespaces ) ) {
            return;
        }

        foreach ( $this->administrativeRoles as $roleNamespace ) {
            $role = Role::namespace( $roleNamespace );

            if ( $role instanceof Role ) {
                $role->addPermissions( $namespaces );
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        /**
         * Nothing cascades from nexopos_permissions, so the role_permission rows have to be
         * cut first — left behind they point at a missing permission id, and a later
         * permission reusing that id would silently inherit the grant.
         */
        Permission::includes( '.withholding-tax' )
            ->get()
            ->each( function ( Permission $permission ) {
                $permission->removeFromRoles();
                $permission->delete();
            } );

        if ( Schema::hasTable( 'nexopos_withholding_taxes' ) ) {
            Schema::dropIfExists( 'nexopos_withholding_taxes' );
        }

        if ( Schema::hasTable( 'nexopos_withholding_tax_sequences' ) ) {
            Schema::dropIfExists( 'nexopos_withholding_tax_sequences' );
        }
    }
};
