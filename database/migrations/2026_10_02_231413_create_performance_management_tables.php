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
        if (! Schema::hasTable('nexopos_performance_metrics')) {
            Schema::create('nexopos_performance_metrics', function (Blueprint $t) {
                $t->id();
                $t->string('name', 150);
                $t->string('unit', 40);
                $t->string('direction', 10);
                $t->text('description');
                $t->unsignedBigInteger('author_id');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('nexopos_performance_goals')) {
            Schema::create('nexopos_performance_goals', function (Blueprint $t) {
                $t->id();
                $t->uuid('request_key')->unique();
                $t->unsignedBigInteger('branch_id')->index();
                $t->unsignedBigInteger('employee_id')->index();
                $t->string('employee_name', 150);
                $t->unsignedBigInteger('metric_id');
                $t->json('metric');
                $t->string('period', 7);
                $t->decimal('target', 18, 4);
                $t->decimal('weight', 8, 2);
                $t->text('note')->nullable();
                $t->string('status', 20)->default('open');
                $t->unsignedBigInteger('author_id');
                $t->text('cancellation_reason')->nullable();
                $t->timestamps();
                $t->unique(['branch_id', 'employee_id', 'metric_id', 'period'], 'perf_goal_period');
            });
        }
        if (! Schema::hasTable('nexopos_performance_evaluations')) {
            Schema::create('nexopos_performance_evaluations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('goal_id')->unique();
                $t->decimal('actual', 18, 4);
                $t->decimal('score', 8, 2);
                $t->text('evidence');
                $t->text('comment');
                $t->unsignedBigInteger('reviewer_id');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('nexopos_performance_reviews')) {
            Schema::create('nexopos_performance_reviews', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('branch_id');
                $t->unsignedBigInteger('employee_id');
                $t->unsignedSmallInteger('year');
                $t->decimal('score', 8, 2);
                $t->json('snapshot');
                $t->text('strengths');
                $t->text('improvements');
                $t->text('plan');
                $t->unsignedBigInteger('reviewer_id');
                $t->timestamps();
                $t->unique(['branch_id', 'employee_id', 'year'], 'perf_annual_employee');
            });
        }
        foreach (['read' => 'ดูผลประเมินพนักงาน', 'manage' => 'กำหนดเป้าหมายและประเมินพนักงาน'] as $key => $name) {
            $p = Permission::firstOrNew(['namespace' => 'nexopos.'.$key.'.performance']);
            $p->name = $name;
            $p->description = $name;
            $p->save();
            Role::namespace('admin')?->addPermissions([$p->namespace]);
        }
    }

    public function down(): void {}
};
