<?php

namespace Tests\Feature;

use App\Classes\Cache;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class CrudExportWorkbookTest extends TestCase
{
    public function test_product_register_download_keeps_barcodes_and_branch_units(): void
    {
        session(['selected_branch_id' => auth()->user()->branch_id]);
        $product = Product::factory()->create(['sku' => '000T76', 'name' => '=SUM(1,2)', 'stock_management' => 'disabled']);
        $unit = Unit::firstOrFail();
        foreach ([auth()->user()->branch_id, 99999] as $branch) {
            $row = new ProductUnitQuantity;
            $row->product_id = $product->id;
            $row->unit_id = $unit->id;
            $row->branch_id = $branch;
            $row->barcode = $branch === 99999 ? 'FOREIGN' : '0001234567890';
            $row->quantity = 12;
            $row->sale_price = 30;
            $row->cogs = 20;
            $row->save();
        }
        $response = $this->postJson('/api/crud/ns.products/export', ['scope' => 'selected', 'entries' => [$product->id]])->assertOk();
        $hash = basename(parse_url($response->json('url'), PHP_URL_PATH));
        $path = storage_path('app/public/'.Cache::get($hash));
        $book = IOFactory::load($path);
        unlink($path);
        $sheet = $book->getSheet(0);
        $this->assertSame('000T76', $sheet->getCell('A2')->getValue());
        $this->assertSame('s', $sheet->getCell('B2')->getDataType());
        $this->assertSame('0001234567890', $sheet->getCell('F2')->getValue());
        $this->assertEquals(30, $sheet->getCell('G2')->getValue());
        $this->assertEquals(2, $book->getSheet(1)->getHighestRow());
        $this->assertEquals(12, $sheet->getCell('Q2')->getValue());
    }

    use DatabaseTransactions, WithAuthentication;

    private array $ids;

    private int $author;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->attemptGetAnyUserFromRole();
        $branch = Branch::create(['code' => 'EXPORT-QA', 'name' => 'Export QA', 'is_active' => true, 'author_id' => $user->id]);
        $user->branch_id = $branch->id;
        $user->assigned_register_id = null;
        $user->save();
        $this->actingAs($user);
        $this->author = $user->id;
        $customer = Customer::firstOrFail();
        $this->ids = [];
        for ($i = 1; $i <= 69; $i++) {
            $this->ids[] = DB::table('nexopos_orders')->insertGetId([
                'code' => sprintf('000%03d', $i), 'author_id' => $user->id,
                'customer_id' => $customer->id, 'branch_id' => $branch->id,
                'type' => 'takeaway', 'payment_status' => 'paid',
                'total' => 107, 'tax_value' => 7,
                'created_at' => $i === 69 ? '2026-08-01 12:00:00' : '2026-09-29 19:10:00',
                'updated_at' => '2026-09-29 19:10:00',
            ]);
        }
        DB::table('nexopos_orders')->insert([
            'code' => '=1+1', 'author_id' => User::where('id', '!=', $user->id)->firstOrFail()->id,
            'customer_id' => $customer->id, 'branch_id' => $branch->id,
            'type' => 'takeaway', 'payment_status' => 'paid', 'total' => 107, 'tax_value' => 7,
            'created_at' => '2026-09-29 19:10:00', 'updated_at' => '2026-09-29 19:10:00',
        ]);
    }

    private function filters(): array
    {
        return ['nexopos_orders.author_id' => $this->author, 'nexopos_orders.created_at' => [
            'startDate' => '2026-09-29 00:00:00', 'endDate' => '2026-09-29 23:59:59',
        ]];
    }

    private function workbook(array $body, array $query = [])
    {
        $response = $this->postJson('/api/crud/ns.orders/export?'.http_build_query($query), $body);
        $response->assertOk();
        $hash = basename(parse_url($response->json('url'), PHP_URL_PATH));
        $relative = Cache::get($hash);
        $this->assertStringEndsWith('.xlsx', $relative);
        $path = storage_path('app/public/'.$relative);
        $book = IOFactory::load($path);
        unlink($path);

        return $book->getActiveSheet();
    }

    public function test_filtered_export_includes_all_68_rows_and_preserves_excel_values(): void
    {
        $sheet = $this->workbook(['scope' => 'filtered'], ['page' => 3, 'per_page' => 20, 'queryFilters' => json_encode($this->filters())]);
        $this->assertEquals(69, $sheet->getHighestRow());
        $this->assertStringStartsWith('000', $sheet->getCell('A2')->getValue());
        $this->assertEquals('s', $sheet->getCell('A2')->getDataType());
        $this->assertEquals(7, $sheet->getCell('F2')->getValue());
        $this->assertEquals(107, $sheet->getCell('G2')->getValue());
        $this->assertEquals('n', $sheet->getCell('G2')->getDataType());
        $this->assertEquals('s', $sheet->getCell('J2')->getDataType());
        $this->assertGreaterThanOrEqual(24, $sheet->getColumnDimension('J')->getWidth());
    }

    public function test_selected_export_is_not_limited_by_page_and_empty_selection_is_rejected(): void
    {
        $sheet = $this->workbook(['scope' => 'selected', 'entries' => array_slice($this->ids, 0, 68)], ['page' => 4, 'per_page' => 20]);
        $this->assertEquals(69, $sheet->getHighestRow());
        $this->postJson('/api/crud/ns.orders/export', ['scope' => 'selected', 'entries' => []])->assertStatus(422);
    }

    public function test_all_ignores_search_but_cannot_escape_branch_scope(): void
    {
        $foreign = Order::where('branch_id', '!=', auth()->user()->branch_id)->first();
        $sheet = $this->workbook(['scope' => 'all', 'entries' => [$foreign?->id ?? 1]], ['search' => 'NO-MATCH', 'queryFilters' => json_encode($this->filters())]);
        $this->assertEquals(71, $sheet->getHighestRow());
        $this->assertEquals(70, count(array_unique(array_slice($sheet->rangeToArray('A1:A71'), 1), SORT_REGULAR)));
        foreach (range(2, 71) as $row) {
            $this->assertEquals('s', $sheet->getCell('A'.$row)->getDataType());
        }
        if ($foreign) {
            $this->postJson('/api/crud/ns.orders/export', ['scope' => 'selected', 'entries' => [$foreign->id]])->assertStatus(403);
        }
    }
}
