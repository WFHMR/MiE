<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nexopos_scheduled_prices')) {
            Schema::create('nexopos_scheduled_prices', function (Blueprint $t) {
                $t->id();
                $t->uuid('request_key')->unique();
                $t->string('name', 150);
                $t->unsignedBigInteger('product_id');
                $t->unsignedBigInteger('unit_id');
                $t->decimal('price', 18, 2);
                $t->dateTime('starts_at');
                $t->dateTime('ends_at');
                $t->boolean('enabled')->default(true);
                $t->unsignedBigInteger('author_id');
                $t->unsignedBigInteger('stopped_by')->nullable();
                $t->text('stop_reason')->nullable();
                $t->timestamp('stopped_at')->nullable();
                $t->timestamps();
                $t->index(['product_id', 'unit_id', 'enabled'], 'scheduled_price_lookup');
            });
        }
        foreach (['read' => 'ดูราคาพิเศษตามช่วงเวลา', 'manage' => 'จัดการราคาพิเศษทุกสาขา'] as $key => $name) {
            $p = Permission::firstOrNew(['namespace' => 'nexopos.'.$key.'.scheduled-prices']);
            $p->name = $name;
            $p->description = $name;
            $p->save();
            Role::namespace('admin')?->addPermissions([$p->namespace]);
        }
    }

    public function down(): void {}
};
