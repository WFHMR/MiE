<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserAttribute;
use App\Services\PerformanceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class PerformanceManagementTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private $s;

    private $b;

    private $u;

    private $metric;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->attemptGetAnyUserFromRole(), 'web');
        $this->b = Branch::create(['code' => 'P66', 'name' => 'Performance QA', 'is_active' => true, 'author_id' => auth()->id()]);
        session(['selected_branch_id' => $this->b->id]);
        $this->u = User::factory()->make(['username' => 'qa-perf-'.Str::random(8), 'branch_id' => $this->b->id, 'active' => true]);
        unset($this->u->email_verified_at);
        $this->u->save();
        $this->u->assignRole('nexopos.store.cashier');
        $attribute = new UserAttribute;
        $attribute->user_id = $this->u->id;
        $attribute->language = 'en';
        $attribute->save();
        $this->s = app(PerformanceService::class);
        $this->metric = $this->s->metric(['name' => 'ยอดขาย', 'unit' => 'บาท', 'direction' => 'higher', 'description' => 'ยอดขายสุทธิจากรายงาน']);
    }

    private function goal(array $overrides = []): int
    {
        return $this->s->goal(array_merge(['request_key' => (string) Str::uuid(), 'branch_id' => $this->b->id, 'employee_id' => $this->u->id, 'metric_id' => $this->metric, 'period' => '2025-01', 'target' => 100, 'weight' => 2], $overrides));
    }

    private function evaluate(int $id): void
    {
        $this->s->evaluate($id, ['actual' => 80, 'evidence' => 'รายงานเลขที่ QA', 'comment' => 'ตรวจสอบแล้ว', 'confirm' => 1]);
    }

    public function test_goal_evaluation_weighted_score_and_annual_snapshot(): void
    {
        $id = $this->goal();
        $this->evaluate($id);
        $id2 = $this->goal(['period' => '2025-02', 'weight' => 1]);
        $this->s->evaluate($id2, ['actual' => 50, 'evidence' => 'รายงานเดือนสอง', 'comment' => 'ตรวจสอบแล้ว', 'confirm' => 1]);
        $this->assertEquals(70, $this->s->summarize($this->s->rows($this->b->id, 2025))['score']);
        $review = $this->s->annual(['branch_id' => $this->b->id, 'employee_id' => $this->u->id, 'year' => 2025, 'strengths' => 'ขายดี', 'improvements' => 'บริการ', 'plan' => 'อบรม', 'confirm' => 1]);
        $this->assertEquals(70, DB::table('nexopos_performance_reviews')->find($review)->score);
        $this->get('/dashboard/performance/annual?branch_id='.$this->b->id.'&year=2025')->assertOk()->assertSee('70.00');
        $this->expectException(HttpException::class);
        $this->goal(['period' => '2025-03']);
    }

    public function test_cannot_evaluate_twice(): void
    {
        $id = $this->goal();
        $this->evaluate($id);
        $this->expectException(HttpException::class);
        $this->evaluate($id);
    }

    public function test_cannot_assign_other_branch_employee(): void
    {
        $this->u->branch_id = 1;
        $this->u->save();
        $this->expectException(ModelNotFoundException::class);
        $this->goal();
    }

    public function test_pending_goals_prevent_final_review(): void
    {
        $this->goal();
        $this->expectException(HttpException::class);
        $this->s->annual(['branch_id' => $this->b->id, 'employee_id' => $this->u->id, 'year' => 2025, 'strengths' => 'ดี', 'improvements' => 'พัฒนา', 'plan' => 'อบรม', 'confirm' => 1]);
    }

    public function test_score_formula_caps_and_handles_zero(): void
    {
        $this->assertEquals(100, PerformanceService::score(150, 100, 'higher'));
        $this->assertEquals(0, PerformanceService::score(0, 100, 'higher'));
        $this->assertEquals(100, PerformanceService::score(0, 2, 'lower'));
        $this->assertEquals(50, PerformanceService::score(4, 2, 'lower'));
    }

    public function test_five_separate_pages_render_with_real_records(): void
    {
        $id = $this->goal();
        $this->evaluate($id);
        foreach (['metrics', 'goals', 'evaluations', 'scores', 'annual'] as $page) {
            $this->get('/dashboard/performance/'.$page.'?branch_id='.$this->b->id.'&year=2025')->assertOk()->assertSee('Performance QA');
        }
    }

    public function test_employee_without_permission_cannot_read_or_write(): void
    {
        $this->actingAs($this->u, 'web');
        $this->get('/dashboard/performance/scores?branch_id='.$this->b->id)->assertForbidden();
        $this->post('/dashboard/performance/actions/metric', ['name' => 'forbidden'])->assertForbidden();
    }

    public function test_request_retry_does_not_duplicate_goal(): void
    {
        $key = (string) Str::uuid();
        $id = $this->goal(['request_key' => $key]);
        $this->assertSame($id, $this->goal(['request_key' => $key]));
    }

    public function test_cancelled_goal_is_visible_but_not_scored(): void
    {
        $id = $this->goal();
        $this->s->cancel($id, ['reason' => 'ยกเลิกเป้าหมายทดสอบ']);
        $summary = $this->s->summarize($this->s->rows($this->b->id, 2025));
        $this->assertNull($summary['score']);
        $this->assertSame(0, $summary['pending']);
    }
}
