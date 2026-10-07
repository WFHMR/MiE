<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Every stock figure has to say which shelf it belongs to.
 *
 * The pages staff read stock on were only half scoped. The per-product quantities page — the one
 * reached by "ดูจำนวน", which is what the shop calls คลังสินค้า — filtered on product_id and nothing
 * else, so a product stocked in three branches rendered three rows carrying the same product name,
 * the same unit name and three different quantities, with nothing to distinguish them. A branch
 * cashier was reading another branch's shelf, and the owner could not tell whose figure was whose.
 *
 * The stock and low-stock reports were worse: they loaded every branch's rows and the page added the
 * quantities together in its footer, presenting a company total as though it were one shelf. Ordering
 * stock from that number over-buys by however many branches there are.
 *
 * A single store install carries no branch on any row, so none of this may exclude branchless rows.
 */
class BranchScopedInventoryViewTest extends TestCase
{
    use WithAuthentication;

    /** @var list<array{0: string, 1: int}> */
    private array $createdRows = [];

    private ?User $actor = null;

    private ?int $branchBefore = null;

    protected function tearDown(): void
    {
        if ( $this->actor instanceof User ) {
            DB::table( 'nexopos_users' )->where( 'id', $this->actor->id )->update( [ 'branch_id' => $this->branchBefore ] );
        }

        foreach ( array_reverse( $this->createdRows ) as [ $table, $id ] ) {
            DB::table( $table )->where( 'id', $id )->delete();
        }

        $this->createdRows = [];

        session()->forget( 'selected_branch_id' );

        parent::tearDown();
    }

    /**
     * The fault as the shop reported it: the admin sees everything, merged.
     *
     * Seeing every branch is right for the owner — comparing one shelf against another is why they
     * open the page. What was wrong is that nothing said which branch a row belonged to.
     */
    public function test_the_quantities_page_says_which_branch_every_row_belongs_to(): void
    {
        [ $product, $branches ] = $this->productStockedInThreeBranches();

        $this->pinActorToBranch( null );

        $rows = $this->quantityRows( $product );

        $this->assertCount(
            3,
            $rows,
            'The owner should still see all three branches on this page.'
        );

        foreach ( $rows as $row ) {
            $this->assertArrayHasKey(
                'branches_name',
                $row,
                'The quantities page carries no branch column, so three identical looking rows are'
                    . ' indistinguishable and no figure can be attributed to a shelf.'
            );

            $this->assertNotEmpty( $row[ 'branches_name' ], 'a row came back with an empty branch label' );
        }

        $labels = array_column( $rows, 'branches_name' );

        $this->assertCount(
            3,
            array_unique( $labels ),
            'Every row shows the same branch label, so the column is not actually reading the row\'s branch.'
        );
    }

    /**
     * The isolation requirement. A member of staff who belongs to a branch must not be shown another
     * branch's shelf, which is the same boundary the till applies when it deducts stock.
     */
    public function test_a_branch_user_sees_only_their_own_branch_on_the_quantities_page(): void
    {
        [ $product, $branches ] = $this->productStockedInThreeBranches();

        $this->pinActorToBranch( $branches[ 1 ] );

        $rows = $this->quantityRows( $product );

        $this->assertCount(
            1,
            $rows,
            'A branch user was shown more than their own branch\'s row on the stock page.'
        );

        $this->assertSame(
            200.0,
            (float) $rows[ 0 ][ 'quantity' ],
            'A branch user was shown a quantity belonging to another branch.'
        );
    }

    /**
     * The regression that would break every shop that has only ever had one branch: those rows carry
     * no branch at all, and scoping them out empties the page.
     */
    public function test_a_row_with_no_branch_is_still_listed(): void
    {
        $group = $this->unitGroup();
        $unit = $this->unit( $group->id );
        $product = $this->product( $group->id );

        $this->unitQuantity( $product->id, $unit->id, branchId: null, quantity: 55 );

        $this->pinActorToBranch( $this->makeBranch( 'สาขาที่ไม่เกี่ยวข้อง' ) );

        $rows = $this->quantityRows( $product );

        $this->assertCount(
            1,
            $rows,
            'A branchless row disappeared. On a single store install every row is branchless, so the'
                . ' whole page would empty.'
        );

        $this->assertSame( 55.0, (float) $rows[ 0 ][ 'quantity' ] );
        $this->assertNotEmpty( $rows[ 0 ][ 'branches_name' ], 'a branchless row must still be labelled, not left blank' );
    }

    /**
     * The report the owner reads. Unscoped it returned all three branches and the page summed them,
     * so 100 + 200 + 300 was presented as one shelf holding 600.
     */
    public function test_the_stock_report_covers_one_branch_at_a_time(): void
    {
        [ $product, $branches ] = $this->productStockedInThreeBranches();

        $this->pinActorToBranch( null );

        session( [ 'selected_branch_id' => $branches[ 2 ] ] );

        $rows = $this->stockReportRowsFor( $product );

        $this->assertCount(
            1,
            $rows,
            'The stock report returned every branch\'s row, and the page adds them up — so the figure'
                . ' the owner reads is a company total presented as a single shelf.'
        );

        $this->assertSame( 300.0, (float) $rows[ 0 ]->quantity, 'the report showed another branch\'s quantity' );
    }

    public function test_the_low_stock_report_covers_one_branch_at_a_time(): void
    {
        [ $product, $branches ] = $this->productStockedInThreeBranches( lowStock: true );

        $this->pinActorToBranch( null );

        session( [ 'selected_branch_id' => $branches[ 0 ] ] );

        $reported = app()->make( ReportService::class )
            ->getLowStockProducts( categories: [], units: [] )
            ->where( 'product_id', $product->id );

        $this->assertCount(
            1,
            $reported,
            'The low stock report listed the same product once per branch, so restocking from it sends'
                . ' goods to whichever branch happened to be read first.'
        );

        $this->assertSame( 100.0, (float) $reported->first()->quantity );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Read through the API the page itself uses, so the joins, the filter and the columns are all
     * exercised together rather than asserted on in isolation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function quantityRows( Product $product ): array
    {
        $response = $this->actingAs( $this->actor(), 'web' )
            ->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', '/api/crud/ns.products-units?product_id=' . $product->id );

        $response->assertOk();

        return $response->json( 'data' ) ?? [];
    }

    /** @return Collection<int, ProductUnitQuantity> */
    private function stockReportRowsFor( Product $product )
    {
        $report = app()->make( ReportService::class )->getStockReport( categories: [], units: [] );

        $found = collect( $report->items() )->firstWhere( 'id', $product->id );

        $this->assertNotNull( $found, 'the product was missing from the stock report entirely' );

        return $found->unit_quantities;
    }

    /**
     * @return array{0: Product, 1: list<int>}
     */
    private function productStockedInThreeBranches( bool $lowStock = false ): array
    {
        $branches = [
            $this->makeBranch( 'สาขาคลังหนึ่ง' ),
            $this->makeBranch( 'สาขาคลังสอง' ),
            $this->makeBranch( 'สาขาคลังสาม' ),
        ];

        $group = $this->unitGroup();
        $unit = $this->unit( $group->id );
        $product = $this->product( $group->id );

        foreach ( [ 100.0, 200.0, 300.0 ] as $index => $quantity ) {
            $this->unitQuantity( $product->id, $unit->id, $branches[ $index ], $quantity, $lowStock );
        }

        return [ $product, $branches ];
    }

    private function makeBranch( string $name ): int
    {
        $id = (int) DB::table( 'nexopos_branches' )->insertGetId( [
            'code' => 'INV' . random_int( 100000, 999999 ),
            'name' => $name . ' ' . random_int( 100, 999 ),
            'is_main' => false,
            'is_active' => true,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_branches', $id ];

        return $id;
    }

    private function unitGroup(): UnitGroup
    {
        $group = new UnitGroup;
        $group->name = 'inv-' . random_int( 100000, 999999 );
        $group->author_id = 1;
        $group->save();

        $this->createdRows[] = [ 'nexopos_units_groups', $group->id ];

        return $group;
    }

    private function unit( int $groupId ): Unit
    {
        $unit = new Unit;
        $unit->name = 'ชิ้นคลัง ' . random_int( 100000, 999999 );
        $unit->identifier = 'inv-' . random_int( 100000, 999999 );
        $unit->group_id = $groupId;
        $unit->value = 1;
        $unit->base_unit = true;
        $unit->author_id = 1;
        $unit->save();

        $this->createdRows[] = [ 'nexopos_units', $unit->id ];

        return $unit;
    }

    private function product( int $unitGroupId ): Product
    {
        $suffix = random_int( 100000, 999999 );

        $product = new Product;
        $product->stock_on_base_unit = false;
        $product->name = 'สินค้าทดสอบคลัง ' . $suffix;
        $product->barcode = 'INV' . $suffix;
        $product->sku = 'inv-' . $suffix;
        $product->barcode_type = 'code128';
        $product->unit_group = $unitGroupId;
        $product->author_id = 1;
        $product->type = Product::TYPE_MATERIALIZED;
        $product->product_type = 'product';
        $product->status = Product::STATUS_AVAILABLE;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->tax_type = 'inclusive';
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->searchable = true;
        $product->save();

        $this->createdRows[] = [ 'nexopos_products', $product->id ];

        return $product;
    }

    private function unitQuantity( int $productId, int $unitId, ?int $branchId, float $quantity, bool $lowStock = false ): void
    {
        $row = new ProductUnitQuantity;
        $row->product_id = $productId;
        $row->unit_id = $unitId;
        $row->branch_id = $branchId;
        $row->quantity = $quantity;
        $row->barcode = 'INVQ' . random_int( 100000, 999999 );
        $row->sale_price = 10;
        $row->sale_price_edit = 10;
        $row->wholesale_price = 10;
        $row->cogs = 10;
        $row->type = 'product';
        $row->visible = true;
        $row->stock_alert_enabled = $lowStock;
        $row->low_quantity = $lowStock ? $quantity + 1 : 0;
        $row->save();

        $this->createdRows[] = [ 'nexopos_products_unit_quantities', $row->id ];
    }

    private function pinActorToBranch( ?int $branchId ): void
    {
        $actor = $this->actor();

        DB::table( 'nexopos_users' )->where( 'id', $actor->id )->update( [ 'branch_id' => $branchId ] );

        /**
         * actingAs hands the guard the in-memory model, and the branch filter reads the branch off
         * it, so without refreshing every request below would be scoped by the previous branch.
         */
        $actor->refresh();
    }

    private function actor(): User
    {
        if ( ! $this->actor instanceof User ) {
            $this->actor = $this->attemptGetAnyUserFromRole();
            $this->branchBefore = $this->actor->branch_id;
        }

        return $this->actor;
    }
}
