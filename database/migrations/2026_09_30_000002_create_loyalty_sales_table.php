<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'nexopos_loyalty_sales', function ( Blueprint $t ): void {
            $t->unsignedBigInteger( 'order_id' )->primary();
            $t->unsignedBigInteger( 'account_id' )->index();
            $t->uuid( 'request_key' )->nullable()->unique();
            $t->unsignedBigInteger( 'earn_satang' );
            $t->unsignedBigInteger( 'redeemed_points' )->default( 0 );
            $t->unsignedBigInteger( 'discount_satang' )->default( 0 );
            $t->unsignedBigInteger( 'paid_satang' )->default( 0 );
            $t->unsignedBigInteger( 'earned_points' )->default( 0 );
            $t->boolean( 'settled' )->default( false );
            $t->timestamps();
        } );
        Schema::create( 'nexopos_loyalty_settings_history', function ( Blueprint $t ): void {
            $t->id();
            $t->unsignedBigInteger( 'branch_id' );
            $t->unsignedBigInteger( 'author_id' );
            $t->json( 'settings' );
            $t->timestamp( 'created_at' );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'nexopos_loyalty_settings_history' );
        Schema::dropIfExists( 'nexopos_loyalty_sales' );
    }
};
