<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\OrdersService;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Stock belongs to a branch, and a movement must reach exactly one of them.
 *
 * The shop runs two branches whose stock lives in the same table, one row per branch per unit.
 * The deduction was keyed on product and unit alone and performed with a mass decrement, so
 * selling one item at Tha Chang took one item off Tha Chang *and* one off the main warehouse.
 * Measured on the live database, 418 product/unit pairs were held in both branches, so the loss
 * compounded on every sale of any of them. The availability check was worse than wrong: it read
 * whichever row had the lowest id, so one branch was cleared to sell against another branch's
 * shelf.
 *
 * The branch is therefore taken from the data that already knows it — the unit quantity row the
 * till sold from, or the order it belongs to — and never inferred from a mass update. A single
 * store install has no branch on its rows at all, and has to keep working untouched, so branch
 * scoping only bites when the rows are genuinely split by branch.
 */
class BranchScopedStockDeductionTest extends TestCase
{
    use DatabaseTransactions;

    private ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productService = app( ProductService::class );
    }

    public function test_selling_at_one_branch_leaves_the_other_branch_alone(): void
    {
        [ $product, $unit, $rows ] = $this->productStockedInTwoBranches( main: 100, other: 60 );

        $this->productService->stockAdjustment( ProductHistory::ACTION_SOLD, [
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'branch_id' => $rows[ 'otherBranchId' ],
            'quantity' => 12,
            'unit_price' => 10,
            'author' => 1,
            'total_price' => 120,
        ] );

        $this->assertSame(
            48.0,
            $this->quantityOf( $product, $unit, $rows[ 'otherBranchId' ] ),
            'The branch that sold has to be the one that loses the stock.'
        );

        $this->assertSame(
            100.0,
            $this->quantityOf( $product, $unit, $rows[ 'mainBranchId' ] ),
            'The other branch must not lose stock it did not sell.'
        );
    }

    /**
     * The till sends the id of the row it sold from, and that row knows its branch. Nothing else
     * has to be threaded through the order for the deduction to land correctly.
     */
    public function test_the_branch_is_taken_from_the_sold_unit_quantity_row(): void
    {
        [ $product, $unit, $rows ] = $this->productStockedInTwoBranches( main: 100, other: 60 );

        $this->productService->stockAdjustment( ProductHistory::ACTION_SOLD, [
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'unit_quantity_id' => $rows[ 'other' ]->id,
            'quantity' => 5,
            'unit_price' => 10,
            'author' => 1,
            'total_price' => 50,
        ] );

        $this->assertSame( 55.0, $this->quantityOf( $product, $unit, $rows[ 'otherBranchId' ] ) );
        $this->assertSame( 100.0, $this->quantityOf( $product, $unit, $rows[ 'mainBranchId' ] ) );
    }

    /**
     * A branch with nothing on the shelf must be refused, not quietly served from the other
     * branch's stock. This is the check that let one branch oversell the whole company.
     */
    public function test_a_branch_cannot_be_sold_stock_that_only_the_other_branch_holds(): void
    {
        [ $product, $unit, $rows ] = $this->productStockedInTwoBranches( main: 100, other: 0 );

        $refused = false;

        try {
            $this->productService->stockAdjustment( ProductHistory::ACTION_SOLD, [
                'product_id' => $product->id,
                'unit_id' => $unit->id,
                'branch_id' => $rows[ 'otherBranchId' ],
                'quantity' => 3,
                'unit_price' => 10,
                'author' => 1,
                'total_price' => 30,
            ] );
        } catch ( \Throwable $e ) {
            $refused = true;
        }

        $this->assertTrue( $refused, 'A branch holding nothing was allowed to sell.' );

        $this->assertSame(
            100.0,
            $this->quantityOf( $product, $unit, $rows[ 'mainBranchId' ] ),
            'The refused sale still took stock off the other branch.'
        );
    }

    public function test_reading_the_quantity_is_scoped_to_the_branch_when_one_is_given(): void
    {
        [ $product, $unit, $rows ] = $this->productStockedInTwoBranches( main: 100, other: 60 );

        $this->assertSame(
            100.0,
            (float) $this->productService->getQuantity( $product->id, $unit->id, $rows[ 'mainBranchId' ] )
        );

        $this->assertSame(
            60.0,
            (float) $this->productService->getQuantity( $product->id, $unit->id, $rows[ 'otherBranchId' ] )
        );
    }

    public function test_receiving_stock_lands_on_the_chosen_branch_only(): void
    {
        [ $product, $unit, $rows ] = $this->productStockedInTwoBranches( main: 100, other: 60 );

        $this->productService->stockAdjustment( ProductHistory::ACTION_ADDED, [
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'branch_id' => $rows[ 'otherBranchId' ],
            'quantity' => 40,
            'unit_price' => 10,
            'author' => 1,
            'total_price' => 400,
        ] );

        $this->assertSame( 100.0, $this->quantityOf( $product, $unit, $rows[ 'otherBranchId' ] ) );
        $this->assertSame(
            100.0,
            $this->quantityOf( $product, $unit, $rows[ 'mainBranchId' ] ),
            'Receiving goods at one branch must not inflate the other.'
        );
    }

    /**
     * "Set the stock to exactly this" reads then writes under a lock. The lock and both halves
     * have to be on the same branch's row.
     */
    public function test_setting_stock_lands_on_the_chosen_branch_only(): void
    {
        [ $product, $unit, $rows ] = $this->productStockedInTwoBranches( main: 100, other: 60 );

        $this->productService->stockAdjustment( ProductHistory::ACTION_SET, [
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'branch_id' => $rows[ 'otherBranchId' ],
            'quantity' => 25,
            'unit_price' => 10,
            'author' => 1,
            'total_price' => 250,
        ] );

        $this->assertSame( 25.0, $this->quantityOf( $product, $unit, $rows[ 'otherBranchId' ] ) );
        $this->assertSame( 100.0, $this->quantityOf( $product, $unit, $rows[ 'mainBranchId' ] ) );
    }

    public function test_the_history_row_records_the_branch(): void
    {
        [ $product, $unit, $rows ] = $this->productStockedInTwoBranches( main: 100, other: 60 );

        $this->productService->stockAdjustment( ProductHistory::ACTION_SOLD, [
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'branch_id' => $rows[ 'otherBranchId' ],
            'quantity' => 4,
            'unit_price' => 10,
            'author' => 1,
            'total_price' => 40,
        ] );

        $history = ProductHistory::where( 'product_id', $product->id )
            ->where( 'operation_type', ProductHistory::ACTION_SOLD )
            ->latest( 'id' )
            ->first();

        $this->assertNotNull( $history );

        $this->assertSame(
            $rows[ 'otherBranchId' ],
            (int) $history->branch_id,
            'Without the branch on the ledger row, a stock report cannot be split per branch.'
        );

        $this->assertSame( 60.0, (float) $history->before_quantity, 'The ledger recorded the wrong branch figures.' );
        $this->assertSame( 56.0, (float) $history->after_quantity );
    }

    /**
     * A carton sale converts into pieces before it is deducted. The piece row it lands on has to
     * belong to the branch that sold the carton.
     */
    public function test_a_carton_sale_converts_and_stays_within_the_same_branch(): void
    {
        $mainBranchId = $this->branchId( 'สาขาหลักทดสอบ', main: true );
        $otherBranchId = $this->branchId( 'สาขาสองทดสอบ', main: false );

        $group = $this->unitGroup( 'branch-carton' );
        $piece = $this->unit( $group->id, 'ชิ้นสาขา', value: 1, base: true );
        $carton = $this->unit( $group->id, 'หีบสาขา', value: 24, base: false );

        $product = $this->product( $group->id, stockOnBaseUnit: true );

        $this->unitQuantity( $product->id, $piece->id, quantity: 100, branchId: $mainBranchId );
        $this->unitQuantity( $product->id, $carton->id, quantity: 0, branchId: $mainBranchId );
        $this->unitQuantity( $product->id, $piece->id, quantity: 72, branchId: $otherBranchId );
        $this->unitQuantity( $product->id, $carton->id, quantity: 0, branchId: $otherBranchId );

        $this->productService->stockAdjustment( ProductHistory::ACTION_SOLD, [
            'product_id' => $product->id,
            'unit_id' => $carton->id,
            'branch_id' => $otherBranchId,
            'quantity' => 1,
            'unit_price' => 400,
            'author' => 1,
            'total_price' => 400,
        ] );

        $this->assertSame(
            48.0,
            $this->quantityOf( $product, $piece, $otherBranchId ),
            'One carton of twenty four had to come off the selling branch pieces.'
        );

        $this->assertSame(
            100.0,
            $this->quantityOf( $product, $piece, $mainBranchId ),
            'The carton sale reached across into the other branch pieces.'
        );
    }

    /**
     * The regression guard. A single store install carries no branch on its stock rows, and the
     * branch resolver will still offer a branch from the session, so scoping has to be ignored
     * when the rows are not split by branch — otherwise every product reads as zero on hand and
     * nothing can be sold at all.
     */
    public function test_a_row_with_no_branch_is_still_deducted(): void
    {
        $group = $this->unitGroup( 'branch-single' );
        $unit = $this->unit( $group->id, 'ชิ้นเดี่ยว', value: 1, base: true );
        $product = $this->product( $group->id, stockOnBaseUnit: false );

        $this->unitQuantity( $product->id, $unit->id, quantity: 30, branchId: null );

        session( [ 'selected_branch_id' => 4242 ] );

        $this->assertSame(
            30.0,
            (float) $this->productService->getQuantity( $product->id, $unit->id ),
            'A branchless row must still report the stock it holds.'
        );

        $this->productService->stockAdjustment( ProductHistory::ACTION_SOLD, [
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'quantity' => 7,
            'unit_price' => 10,
            'author' => 1,
            'total_price' => 70,
        ] );

        $this->assertSame(
            23.0,
            (float) ProductUnitQuantity::where( 'product_id', $product->id )
                ->where( 'unit_id', $unit->id )
                ->value( 'quantity' ),
            'A single store deduction stopped working.'
        );
    }

    /**
     * The cart check has to refuse on the selling branch's own figure.
     *
     * A carton sale is measured against the piece row it converts into. That row was looked up by
     * product and unit alone, so a branch holding twelve pieces was cleared to sell a carton of
     * twenty four because another branch held a hundred. The sale then went through and the
     * deduction landed wherever it could.
     */
    public function test_the_cart_check_refuses_on_the_selling_branch_figure(): void
    {
        $mainBranchId = $this->branchId( 'สาขาหลักทดสอบ', main: true );
        $otherBranchId = $this->branchId( 'สาขาสองทดสอบ', main: false );

        $group = $this->unitGroup( 'branch-check' );
        $piece = $this->unit( $group->id, 'ชิ้นเช็ค', value: 1, base: true );
        $carton = $this->unit( $group->id, 'หีบเช็ค', value: 24, base: false );

        $product = $this->product( $group->id, stockOnBaseUnit: true );

        $this->unitQuantity( $product->id, $piece->id, quantity: 100, branchId: $mainBranchId );
        $this->unitQuantity( $product->id, $carton->id, quantity: 0, branchId: $mainBranchId );

        /**
         * Twelve pieces on this branch's shelf: half a carton.
         */
        $this->unitQuantity( $product->id, $piece->id, quantity: 12, branchId: $otherBranchId );
        $otherCartonRow = $this->unitQuantity( $product->id, $carton->id, quantity: 0, branchId: $otherBranchId );

        $mainCartonRow = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $carton->id )
            ->where( 'branch_id', $mainBranchId )
            ->firstOrFail();

        $ordersService = app( OrdersService::class );

        $message = null;

        try {
            $ordersService->checkQuantityAvailability(
                product: $product,
                productUnitQuantity: $otherCartonRow->load( 'unit' ),
                orderProduct: [
                    'quantity' => 1,
                    'unit_quantity_id' => $otherCartonRow->id,
                ],
                session_identifier: 'branch-check-' . uniqid()
            );
        } catch ( \Throwable $e ) {
            $message = $e->getMessage();
        }

        $this->assertNotNull(
            $message,
            'A branch with twelve pieces was cleared to sell a carton of twenty four, because another branch had stock.'
        );

        /**
         * Asserted on the wording so the test cannot pass because of an unrelated failure — a
         * missing relation or a null unit would otherwise look like a correct refusal.
         */
        $template = __( 'Unable to proceed, there is not enough stock for %s using the unit %s. Requested : %s, available %s' );

        $this->assertMatchesRegularExpression(
            '/' . str_replace( '%s', '.+', preg_quote( $template, '/' ) ) . '/',
            (string) $message,
            'The refusal came from something other than the stock check.'
        );

        /**
         * The positive control: the branch that does hold a carton's worth must still be able to
         * sell it, or the fix would simply have blocked carton sales everywhere.
         */
        $ordersService->checkQuantityAvailability(
            product: $product,
            productUnitQuantity: $mainCartonRow->load( 'unit' ),
            orderProduct: [
                'quantity' => 1,
                'unit_quantity_id' => $mainCartonRow->id,
            ],
            session_identifier: 'branch-check-ok-' . uniqid()
        );

        $this->assertTrue( true, 'The branch holding a hundred pieces could still sell a carton.' );
    }

    /**
     * Exactly one row may be written. Even with no branch supplied, a mass update across the
     * rows of a product is never right.
     */
    public function test_a_movement_writes_exactly_one_row(): void
    {
        [ $product, $unit, $rows ] = $this->productStockedInTwoBranches( main: 100, other: 60 );

        $this->productService->stockAdjustment( ProductHistory::ACTION_SOLD, [
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'branch_id' => $rows[ 'otherBranchId' ],
            'quantity' => 10,
            'unit_price' => 10,
            'author' => 1,
            'total_price' => 100,
        ] );

        $total = (float) ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $unit->id )
            ->sum( 'quantity' );

        $this->assertSame(
            150.0,
            $total,
            'The company total must fall by exactly what was sold, not by a multiple of it.'
        );
    }

    // ------------------------------------------------------------------ fixture

    private function quantityOf( Product $product, Unit $unit, ?int $branchId ): float
    {
        return (float) ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $unit->id )
            ->where( 'branch_id', $branchId )
            ->value( 'quantity' );
    }

    /**
     * @return array{0: Product, 1: Unit, 2: array<string, mixed>}
     */
    private function productStockedInTwoBranches( float $main, float $other ): array
    {
        $mainBranchId = $this->branchId( 'สาขาหลักทดสอบ', main: true );
        $otherBranchId = $this->branchId( 'สาขาสองทดสอบ', main: false );

        $group = $this->unitGroup( 'branch-plain' );
        $unit = $this->unit( $group->id, 'ชิ้นสองสาขา', value: 1, base: true );
        $product = $this->product( $group->id, stockOnBaseUnit: false );

        $mainRow = $this->unitQuantity( $product->id, $unit->id, quantity: $main, branchId: $mainBranchId );
        $otherRow = $this->unitQuantity( $product->id, $unit->id, quantity: $other, branchId: $otherBranchId );

        return [ $product, $unit, [
            'main' => $mainRow,
            'other' => $otherRow,
            'mainBranchId' => $mainBranchId,
            'otherBranchId' => $otherBranchId,
        ] ];
    }

    private function branchId( string $name, bool $main ): int
    {
        $branch = Branch::where( 'name', $name )->first();

        if ( ! $branch instanceof Branch ) {
            $branch = new Branch;
            $branch->name = $name;
            $branch->code = strtoupper( substr( md5( $name ), 0, 6 ) );
            $branch->is_main = $main;
            $branch->author_id = 1;
            $branch->save();
        }

        return (int) $branch->id;
    }

    private function unitGroup( string $prefix ): UnitGroup
    {
        $group = new UnitGroup;
        $group->name = $prefix . '-' . uniqid();
        $group->author_id = 1;
        $group->save();

        return $group;
    }

    private function unit( int $groupId, string $name, float $value, bool $base ): Unit
    {
        $unit = new Unit;
        $unit->name = $name . '-' . uniqid();
        $unit->identifier = 'bs-' . uniqid();
        $unit->group_id = $groupId;
        $unit->value = $value;
        $unit->base_unit = $base;
        $unit->author_id = 1;
        $unit->save();

        return $unit;
    }

    private function product( int $unitGroupId, bool $stockOnBaseUnit ): Product
    {
        $product = new Product;
        $product->stock_on_base_unit = $stockOnBaseUnit;
        $product->name = 'สินค้าทดสอบสาขา ' . uniqid();
        $product->barcode = 'B' . uniqid();
        $product->sku = $product->barcode;
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

        return $product;
    }

    private function unitQuantity( int $productId, int $unitId, float $quantity, ?int $branchId ): ProductUnitQuantity
    {
        $row = new ProductUnitQuantity;
        $row->product_id = $productId;
        $row->unit_id = $unitId;
        $row->branch_id = $branchId;
        $row->quantity = $quantity;
        $row->sale_price = 10;
        $row->sale_price_edit = 10;
        $row->wholesale_price = 10;
        $row->cogs = 10;
        $row->type = 'product';
        $row->visible = true;
        $row->save();

        return $row;
    }
}
