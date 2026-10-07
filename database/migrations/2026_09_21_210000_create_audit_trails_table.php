<?php

/**
 * The record of who did what, so an action can be traced back to the person who took it.
 *
 * Nothing kept this before. Every table carries author_id, which says who last touched a row but
 * not what they changed it from, and says nothing at all about a row that was deleted — the one
 * case somebody always needs to look into. The only audit in the installation covered orders alone
 * (nexopos_order_audits, read by the daily reconciliation sheet). Telescope is installed but its
 * production filter keeps exceptions only, and it is not reachable from the dashboard.
 *
 * The user's name and the branch's name are stored alongside their ids on purpose. An audit row has
 * to stay readable after the staff member leaves and their account is deleted, and a row that reads
 * "user #14" a year later answers nothing.
 *
 * Lives at the root of database/migrations because the root is what deploy/install-app.sh runs
 * through "php artisan migrate --force". Under update/ SetupService marks it executed without
 * running it, and the table would exist nowhere while the migration looked done. Creating its own
 * table makes the root safe: it depends on nothing that the application builds later.
 *
 * @since v6.3.2
 **/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ( Schema::hasTable( 'nexopos_audit_trails' ) ) {
            return;
        }

        Schema::create( 'nexopos_audit_trails', function ( Blueprint $table ) {
            $table->id();

            /**
             * Nullable because an action can be taken with nobody signed in — a scheduled job, an
             * artisan command, a failed sign-in attempt. "ระบบ" is shown for those.
             */
            $table->unsignedBigInteger( 'user_id' )->nullable()->index();
            $table->string( 'user_name' )->nullable();
            $table->unsignedBigInteger( 'branch_id' )->nullable();
            $table->string( 'branch_name' )->nullable();

            /**
             * created, updated, deleted, signed_in, settings_saved. A string rather than an enum so
             * a later release can record something new without a schema change.
             */
            $table->string( 'action', 32 )->index();

            /**
             * The CRUD identifier the action happened on — "ns.products", "ns.users" — or "auth"
             * and "settings" for the things that are not CRUD resources. Indexed because "show me
             * everything that happened to the products" is the question this table exists for.
             */
            $table->string( 'resource', 64 )->index();
            $table->string( 'resource_label' )->nullable();

            $table->unsignedBigInteger( 'record_id' )->nullable();
            $table->string( 'record_label' )->nullable();

            /**
             * 45 characters holds an IPv6 address.
             */
            $table->string( 'ip_address', 45 )->nullable();

            /**
             * The submitted values, with anything that looks like a secret removed and the whole
             * thing capped. Text rather than json: this installation runs MariaDB and SQLite in
             * the test suite, and text behaves identically on both.
             */
            $table->text( 'changes' )->nullable();

            $table->timestamp( 'created_at' )->nullable()->index();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'nexopos_audit_trails' );
    }
};
