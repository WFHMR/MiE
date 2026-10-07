<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ( ! Schema::hasTable( 'nexopos_payment_vouchers' ) ) {
            Schema::create( 'nexopos_payment_vouchers', function ( Blueprint $t ): void {
                $t->id();
                $t->uuid( 'request_key' )->unique();
                $t->unsignedBigInteger( 'branch_id' )->index();
                $t->unsignedBigInteger( 'provider_id' )->index();
                $t->unsignedBigInteger( 'author_id' );
                $t->string( 'status', 20 )->default( 'draft' );
                $t->string( 'mode', 20 );
                $t->date( 'paid_at' );
                $t->string( 'method', 20 );
                $t->string( 'reference', 150 )->nullable();
                $t->text( 'notes' )->nullable();
                $t->decimal( 'total', 18, 5 );
                $t->json( 'snapshot' );
                $t->unsignedBigInteger( 'confirmed_by' )->nullable();
                $t->timestamp( 'confirmed_at' )->nullable();
                $t->timestamps();
            } );
        }
        if ( ! Schema::hasTable( 'nexopos_payment_voucher_receipts' ) ) {
            Schema::create( 'nexopos_payment_voucher_receipts', function ( Blueprint $t ): void {
                $t->id();
                $t->unsignedBigInteger( 'voucher_id' )->index();
                $t->unsignedBigInteger( 'procurement_id' )->unique();
            } );
        }
    }

    public function down(): void {}
};
