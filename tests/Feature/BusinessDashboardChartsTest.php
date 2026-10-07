<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Services\BusinessDashboardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class BusinessDashboardChartsTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    public function test_all_five_pages_render_charts_for_a_new_branch(): void
    {
        $this->actingAs($this->attemptGetAnyUserFromRole(), 'web');
        $branch = Branch::create(['code' => 'T67', 'name' => 'Chart branch', 'is_active' => true, 'author_id' => auth()->id()]);
        foreach (['sales', 'purchase', 'inventory', 'financial', 'kpi'] as $kind) {
            $this->get('/dashboard/business-dashboards/'.$kind.'?branch_id='.$branch->id)
                ->assertOk()->assertSee('dashboard-chart')->assertSee('Chart branch');
        }
    }

    public function test_signed_bars_share_zero_and_escape_labels(): void
    {
        $html = view('pages.dashboard.business.bar-chart', ['chartTitle' => 'Test', 'chartUnit' => 'บาท', 'chartNote' => '', 'chartRows' => [['label' => '<script>alert(1)</script>', 'value' => -50], ['label' => 'Positive', 'value' => 100]]])->render();
        $this->assertStringContainsString('data-value="-50"', $html);
        $this->assertStringContainsString('chart-negative', $html);
        $this->assertStringContainsString('width:33.333333333333%', $html);
        $this->assertStringContainsString('width:66.666666666667%', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_kpi_zero_targets_and_missing_actuals_are_distinct(): void
    {
        $metrics = BusinessDashboardService::METRICS;
        $values = array_fill_keys(array_keys($metrics), null);
        $values['stockout'] = 0;
        $values['margin'] = -28.69;
        $html = view('pages.dashboard.business.charts', ['kind' => 'kpi', 'month' => '2026-10', 'metrics' => $metrics, 'data' => ['values' => $values, 'targets' => ['stockout' => 0, 'margin' => 10]]])->render();
        $this->assertStringContainsString('ไม่เกินเป้าหมาย · ถึงเป้าหมาย', $html);
        $this->assertStringContainsString('อย่างน้อยเท่าเป้าหมาย · ยังไม่ถึงเป้าหมาย', $html);
        $this->assertStringContainsString('ยังไม่มีฐานข้อมูลสำหรับคำนวณผลจริง', $html);
        $this->assertStringContainsString('data-value="-28.69"', $html);
        $this->assertStringNotContainsString('NAN', $html);
        $this->assertStringNotContainsString('INF', $html);
    }

    public function test_sales_graph_preserves_daily_amounts_and_order(): void
    {
        $html = view('pages.dashboard.business.charts', ['kind' => 'sales', 'data' => ['days' => collect([['day' => '2026-10-01', 'total' => 100.25], ['day' => '2026-10-03', 'total' => 250]]), 'team' => collect([['name' => 'Cashier', 'total' => 350.25]])]])->render();
        $this->assertStringContainsString('data-value="100.25"', $html);
        $this->assertStringContainsString('data-value="250"', $html);
        $this->assertStringContainsString('data-value="350.25"', $html);
        $this->assertLessThan(strpos($html, '2026-10-03'), strpos($html, '2026-10-01'));
    }

    public function test_financial_graph_displays_outflows_as_negative(): void
    {
        $html = view('pages.dashboard.business.charts', ['kind' => 'financial', 'data' => ['channels' => ['cash-payment' => 100], 'collections' => 100, 'supplierRefunds' => 0, 'refunds' => 20, 'vouchers' => 150, 'recorded_flow' => -70]])->render();
        $this->assertStringContainsString('data-value="-20"', $html);
        $this->assertStringContainsString('data-value="-150"', $html);
        $this->assertStringContainsString('data-value="-70"', $html);
        $this->assertStringContainsString('เงินสด', $html);
    }
}
