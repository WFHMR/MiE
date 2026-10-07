<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if ( Schema::hasTable( 'nexopos_order_audits' ) ) {
            return;
        }
        Schema::create( 'nexopos_order_audits', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'order_id' )->index();
            $table->string( 'order_code' );
            $table->unsignedBigInteger( 'branch_id' )->nullable();
            $table->unsignedBigInteger( 'actor_id' )->nullable();
            $table->string( 'action', 32 );
            $table->text( 'before_data' );
            $table->text( 'after_data' );
            $table->timestamp( 'created_at' );
            $table->index( ['branch_id', 'created_at'], 'order_audit_branch_date' );
        } );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists( 'nexopos_order_audits' );
    }
};
