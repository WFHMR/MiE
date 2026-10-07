<?php

/**
 * Table Migration: Branches
 * Purpose: Multi-branch support with separate inventory per branch
 **/

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('nexopos_branches')) {
            Schema::createIfMissing('nexopos_branches', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('code')->unique(); // รหัสสาขา เช่น BKK01, CNX01
                $table->string('name'); // ชื่อสาขา
                $table->text('description')->nullable(); // รายละเอียด
                $table->text('address')->nullable(); // ที่อยู่
                $table->string('phone')->nullable(); // เบอร์โทร
                $table->string('email')->nullable(); // อีเมล
                $table->boolean('is_active')->default(true); // สถานะเปิด/ปิด
                $table->boolean('is_main')->default(false); // สาขาหลัก (main branch)
                $table->integer('author_id'); // ผู้สร้าง
                $table->string('uuid')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('nexopos_branches')) {
            Schema::dropIfExists('nexopos_branches');
        }
    }
};
