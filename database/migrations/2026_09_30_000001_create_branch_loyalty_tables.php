<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'nexopos_loyalty_settings', function ( Blueprint $t ): void {
            $t->unsignedBigInteger( 'branch_id' )->primary();
            $t->boolean( 'enabled' )->default( false );
            $t->unsignedBigInteger( 'earn_satang' );
            $t->unsignedInteger( 'redeem_points' );
            $t->unsignedBigInteger( 'redeem_satang' );
            $t->unsignedBigInteger( 'updated_by' );
            $t->timestamps();
        } );
        Schema::create( 'nexopos_loyalty_accounts', function ( Blueprint $t ): void {
            $t->id();
            $t->unsignedBigInteger( 'branch_id' );
            $t->unsignedBigInteger( 'customer_id' );
            $t->string( 'member_code', 80 )->nullable();
            $t->bigInteger( 'points' )->default( 0 );
            $t->timestamps();
            $t->unique( ['branch_id', 'customer_id'], 'loyalty_customer_branch' );
            $t->unique( ['branch_id', 'member_code'], 'loyalty_member_branch' );
        } );
        Schema::create( 'nexopos_loyalty_entries', function ( Blueprint $t ): void {
            $t->id();
            $t->unsignedBigInteger( 'account_id' )->index();
            $t->string( 'reference', 120 )->unique();
            $t->bigInteger( 'points' );
            $t->bigInteger( 'balance' );
            $t->string( 'description' );
            $t->unsignedBigInteger( 'author_id' )->nullable();
            $t->timestamp( 'created_at' );
        } );
        Schema::create( 'nexopos_member_imports', function ( Blueprint $t ): void {
            $t->uuid( 'id' )->primary();
            $t->unsignedBigInteger( 'branch_id' );
            $t->unsignedBigInteger( 'author_id' );
            $t->unsignedBigInteger( 'group_id' );
            $t->string( 'status', 20 )->default( 'preview' );
            $t->json( 'rows' );
            $t->timestamp( 'confirmed_at' )->nullable();
            $t->timestamps();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'nexopos_member_imports' );
        Schema::dropIfExists( 'nexopos_loyalty_entries' );
        Schema::dropIfExists( 'nexopos_loyalty_accounts' );
        Schema::dropIfExists( 'nexopos_loyalty_settings' );
    }
};
