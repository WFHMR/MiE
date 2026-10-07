<?php

namespace Tests\Feature;

use App\Crud\BranchCrud;
use App\Crud\UserCrud;
use App\Exceptions\NotAllowedException;
use App\Http\Controllers\Dashboard\ProductsController;
use App\Http\Controllers\InventoryUploadController;
use App\Http\Middleware\SetDefaultBranch;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Register;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use App\Services\OrdersService;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The shop runs two branches today and intends to open more. Everything that made the second branch
 * work has to work for the third and the tenth without a code change, a seeder or a hand written
 * query — that is the whole point of the requirement.
 *
 * Three branches is the smallest number that catches the mistakes two cannot. With two, "the other
 * branch" is unambiguous, so a fallback that reaches for the wrong shelf still looks right half the
 * time; with three it has somewhere wrong to go that is neither the seller nor head office. These
 * tests are therefore written against three throughout.
 */
class MultiBranchScalingTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productService = app( ProductService::class );
    }

    /**
     * Stock is separate per branch however many there are, and a sale reaches exactly one shelf.
     */
    public function test_selling_at_the_third_branch_leaves_the_other_two_alone(): void
    {
        [ $product, $unit, $branches ] = $this->productStockedInThreeBranches( 100, 200, 300 );

        $this->productService->stockAdjustment( ProductHistory::ACTION_SOLD, [
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'branch_id' => $branches[ 2 ],
            'quantity' => 30,
            'unit_price' => 10,
            'total_price' => 300,
            'author' => 1,
        ] );

        $this->assertSame( 100.0, $this->quantityOf( $product, $unit, $branches[ 0 ] ), 'Branch one lost stock it did not sell.' );
        $this->assertSame( 200.0, $this->quantityOf( $product, $unit, $branches[ 1 ] ), 'Branch two lost stock it did not sell.' );
        $this->assertSame( 270.0, $this->quantityOf( $product, $unit, $branches[ 2 ] ) );

        $this->assertSame(
            570.0,
            (float) ProductUnitQuantity::where( 'product_id', $product->id )->where( 'unit_id', $unit->id )->sum( 'quantity' ),
            'The company total must fall by exactly what was sold.'
        );
    }

    /**
     * A branch holding nothing is refused even when two other branches are full. With two branches a
     * wrong fallback has one place to go; with three it could pick either, and both are wrong.
     */
    public function test_the_third_branch_cannot_borrow_from_either_of_the_others(): void
    {
        [ $product, $unit, $branches ] = $this->productStockedInThreeBranches( 100, 200, 0 );

        $refused = false;

        try {
            $this->productService->stockAdjustment( ProductHistory::ACTION_SOLD, [
                'product_id' => $product->id,
                'unit_id' => $unit->id,
                'branch_id' => $branches[ 2 ],
                'quantity' => 5,
                'unit_price' => 10,
                'total_price' => 50,
                'author' => 1,
            ] );
        } catch ( \Throwable $e ) {
            $refused = true;
        }

        $this->assertTrue( $refused );
        $this->assertSame( 100.0, $this->quantityOf( $product, $unit, $branches[ 0 ] ) );
        $this->assertSame( 200.0, $this->quantityOf( $product, $unit, $branches[ 1 ] ) );
    }

    /**
     * The upload page is the way stock gets into a branch, so a new branch has to be loadable the
     * same way branch two was, without disturbing the branches already trading.
     */
    public function test_a_csv_upload_reaches_only_the_branch_it_names(): void
    {
        $this->attemptAuthenticate();

        $branches = [
            $this->makeBranch( 'สาขาอัพหนึ่ง', isMain: true ),
            $this->makeBranch( 'สาขาอัพสอง' ),
            $this->makeBranch( 'สาขาอัพสาม' ),
        ];

        $barcode = '9993330000019';

        $this->assertNull( Product::where( 'barcode', $barcode )->first() );

        /**
         * Same file, three branches, three different received quantities. Each has to stand alone.
         */
        foreach ( [ 0 => 10.0, 1 => 25.0, 2 => 40.0 ] as $index => $quantity ) {
            $this->uploadOneRow( $branches[ $index ], $barcode, $quantity );
        }

        $product = Product::where( 'barcode', $barcode )->first();

        $this->assertNotNull( $product, 'The upload created no product.' );

        $this->assertSame(
            1,
            Product::where( 'barcode', $barcode )->count(),
            'Three branch uploads of one product must not make three products.'
        );

        foreach ( [ 0 => 10.0, 1 => 25.0, 2 => 40.0 ] as $index => $expected ) {
            $this->assertSame(
                $expected,
                (float) ProductUnitQuantity::where( 'barcode', $barcode )
                    ->where( 'branch_id', $branches[ $index ] )
                    ->value( 'quantity' ),
                sprintf( 'Branch %d did not keep its own figure.', $index + 1 )
            );
        }
    }

    /**
     * The same barcode exists once per branch. A till has to be handed its own branch's row, or it
     * shows another branch's price and puts another branch's row into the cart.
     *
     * With no till open the picker is the only thing left to go on, so it decides. Any till this user
     * left open elsewhere is released first: the scan now follows the till, and a till held open in
     * another branch would otherwise answer for a screen that is not in front of anybody. Restored
     * afterwards, because it belongs to another test's fixture.
     */
    public function test_a_scan_returns_the_scanning_branch_row_with_three_branches(): void
    {
        $this->attemptAuthenticate();

        $branches = [
            $this->makeBranch( 'สาขายิงหนึ่ง', isMain: true ),
            $this->makeBranch( 'สาขายิงสอง' ),
            $this->makeBranch( 'สาขายิงสาม' ),
        ];

        $barcode = '9993330000026';

        foreach ( $branches as $index => $branchId ) {
            $this->uploadOneRow( $branchId, $barcode, 5.0 + $index );
        }

        $heldTills = DB::table( 'nexopos_registers' )
            ->where( 'used_by', Auth::id() )
            ->where( 'status', Register::STATUS_OPENED )
            ->pluck( 'id' );

        DB::table( 'nexopos_registers' )->whereIn( 'id', $heldTills )->update( [ 'used_by' => null ] );

        $controller = app()->make( ProductsController::class );

        try {
            foreach ( $branches as $branchId ) {
                session( [ 'selected_branch_id' => $branchId ] );

                $scanned = $controller->searchUsingArgument( $barcode );

                $this->assertSame(
                    $branchId,
                    (int) ( $scanned[ 'unitQuantity' ]->branch_id ?? 0 ),
                    'A scan handed over another branch row.'
                );
            }
        } finally {
            DB::table( 'nexopos_registers' )->whereIn( 'id', $heldTills )->update( [ 'used_by' => Auth::id() ] );

            session()->forget( 'selected_branch_id' );
        }
    }

    /**
     * And when a till is open, it outranks the picker for the scan as well as for the sale. This is the
     * situation the shop was in: an administrator ringing up on the Tha Chang till with the picker left
     * on the main branch, so every scan was answered from a branch the cashier was not standing in.
     */
    public function test_a_scan_follows_the_open_till_rather_than_the_picker(): void
    {
        $this->attemptAuthenticate();

        $tillBranch = $this->makeBranch( 'สาขาลิ้นชักยิง', isMain: true );
        $pickerBranch = $this->makeBranch( 'สาขาที่หัวจอชี้' );

        $barcode = '9993330000033';

        $this->uploadOneRow( $tillBranch, $barcode, 11.0 );
        $this->uploadOneRow( $pickerBranch, $barcode, 22.0 );

        $registerId = $this->makeRegister( $tillBranch );

        DB::table( 'nexopos_registers' )->where( 'id', $registerId )->update( [
            'status' => Register::STATUS_OPENED,
            'used_by' => Auth::id(),
        ] );

        session( [ 'selected_branch_id' => $pickerBranch ] );

        /**
         * Driven through the route with the till named on the request, which is what the POS does. A
         * cashier who left a till open somewhere else holds two, and no rule can tell from the outside
         * which screen is in front of them — so the screen says.
         */
        $response = $this->actingAs( Auth::user(), 'web' )
            ->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', '/api/products/search/using-barcode/' . rawurlencode( $barcode ) . '?register_id=' . $registerId );

        $response->assertOk();

        $scanned = (object) [
            'unitQuantity' => (object) $response->json( 'unitQuantity' ),
        ];

        session()->forget( 'selected_branch_id' );

        $this->assertSame(
            $tillBranch,
            (int) ( $scanned->unitQuantity->branch_id ?? 0 ),
            'The scan followed the branch picker while a till of another branch was open, which is how a'
                . ' cashier was shown another branch\'s price for the goods in their hand.'
        );

        /**
         * The fixture prices both branches the same and separates them by quantity, so the quantity is
         * what proves which shelf answered.
         */
        $this->assertEqualsWithDelta(
            11.0,
            (float) $scanned->unitQuantity->quantity,
            0.001,
            'the row that answered belongs to the branch the picker names, not the branch of the till'
        );
    }

    /**
     * The till decides, not the picker. With three branches a cashier could otherwise seat
     * themselves in either of the two they do not work in.
     */
    public function test_the_till_decides_the_branch_among_three(): void
    {
        $branches = [
            $this->makeBranch( 'สาขาลิ้นชักหนึ่ง', isMain: true ),
            $this->makeBranch( 'สาขาลิ้นชักสอง' ),
            $this->makeBranch( 'สาขาลิ้นชักสาม' ),
        ];

        $registerId = $this->makeRegister( $branches[ 2 ] );

        session( [ 'selected_branch_id' => $branches[ 0 ] ] );

        $order = new Order;
        $order->register_id = $registerId;

        $method = new \ReflectionMethod( OrdersService::class, 'resolveOrderBranch' );
        $resolved = $method->invoke( app( OrdersService::class ), [ 'register_id' => $registerId ], $order );

        session()->forget( 'selected_branch_id' );

        $this->assertSame(
            $branches[ 2 ],
            $resolved,
            'A sale on the third branch till was filed elsewhere because the picker said so.'
        );
    }

    /**
     * A cashier may only work in their own branch, whichever of the many it is.
     */
    public function test_a_cashier_may_only_select_their_own_branch(): void
    {
        $branches = [
            $this->makeBranch( 'สาขาสิทธิ์หนึ่ง', isMain: true ),
            $this->makeBranch( 'สาขาสิทธิ์สอง' ),
            $this->makeBranch( 'สาขาสิทธิ์สาม' ),
        ];

        $user = User::first();
        $original = $user->branch_id;

        $user->branch_id = $branches[ 2 ];
        $user->save();

        $selectable = Branch::selectableBy( $user )->pluck( 'id' )->all();

        $this->assertSame( [ $branches[ 2 ] ], $selectable, 'A branch cashier was offered other branches.' );

        /**
         * The owner keeps the run of the company, which is what the picker is for.
         */
        $user->branch_id = null;
        $user->save();

        $ownerSelectable = Branch::selectableBy( $user )->pluck( 'id' )->all();

        foreach ( $branches as $branchId ) {
            $this->assertContains( $branchId, $ownerSelectable );
        }

        $user->branch_id = $original;
        $user->save();
    }

    /**
     * The middleware corrects a stale choice instead of keeping it, so a cashier reassigned between
     * branches does not carry the old one into their next request.
     */
    public function test_a_stale_branch_choice_is_corrected(): void
    {
        $branches = [
            $this->makeBranch( 'สาขาค้างหนึ่ง', isMain: true ),
            $this->makeBranch( 'สาขาค้างสอง' ),
        ];

        $user = User::first();
        $original = $user->branch_id;

        $user->branch_id = $branches[ 1 ];
        $user->save();

        Auth::login( $user );

        /**
         * A branch they do not work in, left over in the session.
         */
        session( [ 'selected_branch_id' => $branches[ 0 ] ] );

        app( SetDefaultBranch::class )->handle(
            Request::create( '/dashboard', 'GET' ),
            fn ( $request ) => response( 'ok' )
        );

        $corrected = session( 'selected_branch_id' );

        Auth::logout();
        session()->forget( 'selected_branch_id' );
        $user->branch_id = $original;
        $user->save();

        $this->assertSame( $branches[ 1 ], (int) $corrected, 'A stale branch choice survived into the request.' );
    }

    /**
     * Opening a branch has to leave it able to sell. It previously left a row in a table and nothing
     * else, and the commands that finish the job have no screen behind them.
     */
    public function test_a_new_branch_gets_a_till_so_it_can_trade(): void
    {
        $branch = new Branch;
        $branch->name = 'สาขาเปิดใหม่ทดสอบ';
        $branch->code = 'NEWB' . random_int( 100, 999 );
        $branch->is_main = false;
        $branch->is_active = true;
        $branch->author_id = 1;
        $branch->save();

        $this->assertSame( 0, $branch->registers()->count() );

        app( BranchCrud::class )->afterPost( Request::create( '/', 'POST' ), $branch );

        $this->assertSame(
            1,
            $branch->registers()->count(),
            'A branch with no till cannot sell, so creating one has to provide the first.'
        );

        $register = $branch->registers()->first();

        $this->assertSame( $branch->id, (int) $register->branch_id );
        $this->assertSame( Register::STATUS_CLOSED, $register->status );
    }

    /**
     * Binding a cashier to a till in a different branch files their sales in one branch and takes
     * the stock off another. It is refused where it would be created.
     */
    public function test_a_cashier_cannot_be_bound_to_another_branch_till(): void
    {
        $branchA = $this->makeBranch( 'สาขาผูกหนึ่ง', isMain: true );
        $branchB = $this->makeBranch( 'สาขาผูกสอง' );

        $registerInB = $this->makeRegister( $branchB );

        $crud = app( UserCrud::class );

        $this->expectException( NotAllowedException::class );

        $crud->beforePost( Request::create( '/', 'POST', [
            'branch_id' => $branchA,
            'assigned_register_id' => $registerInB,
        ] ) );
    }

    /**
     * The real payload shape, which is what caught this out.
     *
     * CRUD hooks are handed a plain array on one path and a Request on another, and the fields sit
     * nested under the tab that declared them rather than at the top level. The first version of
     * this guard read only the top level of a Request: it looked right, threw a fatal on the array
     * path, and on the shape it did accept it never fired at all.
     */
    public function test_the_guard_reads_the_nested_crud_payload(): void
    {
        $branchA = $this->makeBranch( 'สาขาซ้อนหนึ่ง', isMain: true );
        $branchB = $this->makeBranch( 'สาขาซ้อนสอง' );

        $registerInB = $this->makeRegister( $branchB );

        $crud = app( UserCrud::class );

        $refused = false;

        try {
            /**
             * A plain array, fields under the tab, exactly as the CRUD delivers it.
             */
            $crud->beforePost( [
                'username' => 'nested' . random_int( 1000, 9999 ),
                'general' => [
                    'branch_id' => $branchA,
                    'assigned_register_id' => $registerInB,
                ],
            ] );
        } catch ( NotAllowedException $e ) {
            $refused = true;
        }

        $this->assertTrue(
            $refused,
            'The guard did not see fields nested under a tab, which is the shape it actually receives.'
        );
    }

    /**
     * The matching pair is accepted, so the guard above cannot be passing by refusing everything.
     */
    public function test_a_cashier_bound_to_their_own_branch_till_is_accepted(): void
    {
        $branchId = $this->makeBranch( 'สาขาผูกถูก', isMain: true );
        $registerId = $this->makeRegister( $branchId );

        $request = Request::create( '/', 'POST', [
            'branch_id' => $branchId,
            'assigned_register_id' => $registerId,
        ] );

        $this->assertSame(
            $request,
            app( UserCrud::class )->beforePost( $request ),
            'A cashier bound to a till in their own branch has to be allowed.'
        );
    }

    /**
     * The upload page must offer every branch that trades, and no branch that does not.
     */
    public function test_the_upload_page_lists_every_active_branch(): void
    {
        $active = $this->makeBranch( 'สาขาเปิดอยู่' );
        $inactive = $this->makeBranch( 'สาขาปิดแล้ว' );

        Branch::where( 'id', $inactive )->update( [ 'is_active' => false ] );

        $offered = app( InventoryUploadController::class )->index()->getData()[ 'branches' ]->pluck( 'id' )->all();

        $this->assertContains( $active, $offered );
        $this->assertNotContains( $inactive, $offered, 'A branch that has been switched off must not be an import target.' );

        foreach ( Branch::where( 'is_active', true )->pluck( 'id' ) as $id ) {
            $this->assertContains( (int) $id, $offered, 'Every trading branch has to be offered.' );
        }
    }

    // ------------------------------------------------------------------ fixture

    private function uploadOneRow( int $branchId, string $barcode, float $quantity ): void
    {
        $header = 'รหัสสต็อก,ชื่อสินค้า,กลุ่มหลัก,ย่อย,ราคาทุน,บาร์โค้ดชิ้น,ราคาชิ้น,จำนวนรับเข้า,หน่วยที่นับ';
        $row = implode( ',', [ 'MB1', 'ทดสอบหลายสาขา', 'ทดสอบ', 'ทดสอบ', '8.00', $barcode, '12.00', (string) $quantity, '' ] );

        $csv = implode( "\n", [ 'ทดสอบ,,,,,,,,', $header, $row ] ) . "\n";

        app()->make( InventoryUploadController::class )->upload( Request::create(
            '/dashboard/inventory/upload',
            'POST',
            [ 'branch_id' => $branchId, 'stock_mode' => 'set' ],
            [],
            [ 'csv_file' => UploadedFile::fake()->createWithContent( 'mb.csv', $csv ) ]
        ) );
    }

    private function quantityOf( Product $product, Unit $unit, int $branchId ): float
    {
        return (float) ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $unit->id )
            ->where( 'branch_id', $branchId )
            ->value( 'quantity' );
    }

    /**
     * @return array{0: Product, 1: Unit, 2: list<int>}
     */
    private function productStockedInThreeBranches( float $first, float $second, float $third ): array
    {
        $branches = [
            $this->makeBranch( 'สาขาสต๊อกหนึ่ง', isMain: true ),
            $this->makeBranch( 'สาขาสต๊อกสอง' ),
            $this->makeBranch( 'สาขาสต๊อกสาม' ),
        ];

        $group = new UnitGroup;
        $group->name = 'mb-' . uniqid();
        $group->author_id = 1;
        $group->save();

        $unit = new Unit;
        $unit->name = 'ชิ้นสามสาขา-' . uniqid();
        $unit->identifier = 'mb-' . uniqid();
        $unit->group_id = $group->id;
        $unit->value = 1;
        $unit->base_unit = true;
        $unit->author_id = 1;
        $unit->save();

        $product = new Product;
        $product->stock_on_base_unit = false;
        $product->name = 'สินค้าทดสอบสามสาขา ' . uniqid();
        $product->barcode = 'M' . uniqid();
        $product->sku = $product->barcode;
        $product->barcode_type = 'code128';
        $product->unit_group = $group->id;
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

        foreach ( [ $first, $second, $third ] as $index => $quantity ) {
            $row = new ProductUnitQuantity;
            $row->product_id = $product->id;
            $row->unit_id = $unit->id;
            $row->branch_id = $branches[ $index ];
            $row->quantity = $quantity;
            $row->sale_price = 10;
            $row->sale_price_edit = 10;
            $row->wholesale_price = 10;
            $row->cogs = 10;
            $row->type = 'product';
            $row->visible = true;
            $row->save();
        }

        return [ $product, $unit, $branches ];
    }

    private function makeBranch( string $name, bool $isMain = false ): int
    {
        if ( $isMain ) {
            DB::table( 'nexopos_branches' )->where( 'is_main', 1 )->update( [ 'is_main' => 0 ] );
        }

        return (int) DB::table( 'nexopos_branches' )->insertGetId( [
            'code' => 'MB' . uniqid(),
            'name' => $name,
            'is_main' => $isMain,
            'is_active' => true,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );
    }

    private function makeRegister( ?int $branchId ): int
    {
        return (int) DB::table( 'nexopos_registers' )->insertGetId( [
            'name' => 'ลิ้นชักทดสอบ ' . uniqid(),
            'branch_id' => $branchId,
            'status' => Register::STATUS_CLOSED,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );
    }
}
