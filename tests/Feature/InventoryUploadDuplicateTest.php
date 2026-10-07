<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\User;
use App\Services\InventoryImportLock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * An upload must never create the same product twice.
 *
 * The shop's own export lists a product once per group code it belongs to, so a product filed
 * under two groups arrives on two rows carrying the same barcode, the same name, the same packing
 * and the same price. The importer created a product for each of those rows. On the live database
 * that produced 40 duplicated products, and because each duplicate carried its own copy of the
 * branch's stock rows the branch's stock was counted twice: 954 units that did not exist.
 *
 * The lookup that was supposed to prevent this searched by barcode and looked correct, which is
 * why the fault survived. These tests drive the upload endpoint with a file shaped like the real
 * one, so they fail against the original code rather than only describing it.
 */
class InventoryUploadDuplicateTest extends TestCase
{
    use WithAuthentication;

    /**
     * Rows created by the upload, removed afterwards.
     *
     * Explicit cleanup rather than DatabaseTransactions: the controller wraps every row in its own
     * DB::transaction(), and an outer test transaction around that makes SQLite report
     * "database is locked" for the rest of the suite.
     *
     * @var list<array{0: string, 1: int}>
     */
    private array $createdRows = [];

    private string $barcodePiece = '';

    private string $barcodeCase = '';

    private int $uploadCount = 0;

    /**
     * Highest unit and unit-group id that existed before this test ran.
     *
     * The controller creates a unit group and its units on the way to creating a product, and the
     * test cannot know their ids in advance. Recording the watermark is what lets tearDown tell
     * "created by this test" from "was already here".
     *
     * Leaving them behind was not harmless. A NexoPOS fixture elsewhere in the suite attaches its
     * own piece/dozen/thirty units to the first unit group it finds, which became this test's
     * leftover group. That group then held two base units — this test's and the fixture's — and
     * UnitService::getBaseUnit() refuses such a group, so CreateOrderTest failed whenever its
     * random product landed in it. An intermittent failure in an unrelated test, caused from here.
     */
    private int $unitGroupWatermark = 0;

    private int $unitWatermark = 0;

    /**
     * The one user every upload in a test acts as.
     *
     * attemptGetAnyUserFromRole() picks a random admin on each call, so resolving it per upload
     * meant the second upload of a test could act as a different user. That made the run
     * intermittent: sometimes the second request arrived unauthenticated and was redirected to
     * the sign in page, so the import never happened and the test read the first upload's figures.
     */
    private ?User $actor = null;

    /** A second, genuinely different product created by one of the tests. */
    private string $extraBarcode = '';

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = str_pad( (string) random_int( 0, 99999 ), 5, '0', STR_PAD_LEFT );

        $this->barcodePiece = '77' . $suffix . '0001';
        $this->barcodeCase = '77' . $suffix . '0002';

        $this->unitGroupWatermark = (int) DB::table( 'nexopos_units_groups' )->max( 'id' );
        $this->unitWatermark = (int) DB::table( 'nexopos_units' )->max( 'id' );
    }

    protected function tearDown(): void
    {
        $products = Product::whereIn(
            'barcode',
            array_filter( [ $this->barcodePiece, $this->barcodeCase, $this->extraBarcode ] )
        )->pluck( 'id' );

        if ( $products->isNotEmpty() ) {
            DB::table( 'nexopos_products_unit_quantities' )->whereIn( 'product_id', $products )->delete();
            DB::table( 'nexopos_products_histories' )->whereIn( 'product_id', $products )->delete();
            DB::table( 'nexopos_products_histories_combined' )->whereIn( 'product_id', $products )->delete();
            DB::table( 'nexopos_products' )->whereIn( 'id', $products )->delete();
        }

        foreach ( $this->createdRows as [ $table, $id ] ) {
            DB::table( $table )->where( 'id', $id )->delete();
        }

        $this->createdRows = [];

        $this->removeImportedUnits();

        parent::tearDown();
    }

    /**
     * The fault itself: one product listed twice must load once.
     */
    public function test_two_rows_for_the_same_product_create_one_product(): void
    {
        $this->uploadRows( [
            $this->ladderRow( '69090135' ),
            $this->ladderRow( '69090244' ),
        ] );

        $products = Product::where( 'barcode', $this->barcodePiece )->get();

        $this->assertCount(
            1,
            $products,
            sprintf(
                'The same product arrived on two rows and was loaded %d times. Each copy carries its own stock rows, so the branch stock is counted once per copy.',
                $products->count()
            )
        );
    }

    /**
     * The consequence that reached the shop floor. Asserted separately so a regression says
     * whether the count is wrong as well as whether the row is duplicated.
     */
    public function test_the_stock_is_not_counted_twice(): void
    {
        $this->uploadRows( [
            $this->ladderRow( '69090135' ),
            $this->ladderRow( '69090244' ),
        ] );

        $product = Product::where( 'barcode', $this->barcodePiece )->first();

        $this->assertInstanceOf( Product::class, $product );

        $rows = ProductUnitQuantity::whereIn(
            'product_id',
            Product::where( 'barcode', $this->barcodePiece )->pluck( 'id' )
        )->get();

        /**
         * Two sellable levels in the file means two rows for the branch, whatever the file says
         * twice. Four would mean a second product is holding a second copy.
         */
        $this->assertCount(
            2,
            $rows,
            'The branch holds more unit rows than the file described, which is stock counted twice.'
        );

        $base = $rows->firstWhere( 'barcode', $this->barcodePiece );

        $this->assertNotNull( $base, 'The base unit row is missing.' );
        $this->assertSame(
            10.0,
            (float) $base->quantity,
            'The opening stock was added once per duplicate row instead of once.'
        );
    }

    /**
     * A second upload of the same file is a normal thing for a shop to do, and must update rather
     * than duplicate. This is what findProductForLadder() was written for.
     */
    public function test_uploading_the_same_file_again_updates_instead_of_duplicating(): void
    {
        $this->uploadRows( [ $this->ladderRow( '69090135' ) ] );
        $this->uploadRows( [ $this->ladderRow( '69090135' ) ] );

        $this->assertCount(
            1,
            Product::where( 'barcode', $this->barcodePiece )->get(),
            'Uploading the same file twice created the product twice.'
        );
    }

    /**
     * Two genuinely different products must still both load. Without this the suite would pass
     * against an importer that refuses every second row.
     */
    public function test_two_different_products_both_load(): void
    {
        /**
         * Registered before the upload rather than cleaned up at the end, so a failing assertion
         * cannot leave the product behind for the next run to trip over.
         */
        $this->extraBarcode = '78' . str_pad( (string) random_int( 0, 99999 ), 5, '0', STR_PAD_LEFT ) . '9999';

        $this->uploadRows( [
            $this->ladderRow( '69090135' ),
            $this->ladderRow( '69090135', $this->extraBarcode, 'สินค้าทดสอบตัวที่สอง' ),
        ] );

        $first = Product::where( 'barcode', $this->barcodePiece )->get();
        $second = Product::where( 'barcode', $this->extraBarcode )->get();

        $this->assertCount(
            1,
            $first,
            sprintf( 'Expected one product for %s, found %d.', $this->barcodePiece, $first->count() )
        );

        $this->assertCount(
            1,
            $second,
            sprintf(
                'A genuinely different product (%s) did not load exactly once, found %d, so the guard is refusing too much.',
                $this->extraBarcode,
                $second->count()
            )
        );
    }

    /**
     * The actual cause on the live system.
     *
     * A large file outlasts the browser's patience. The browser gives up, nginx logs 499, PHP keeps
     * importing, and the operator presses the button again because nothing appears to be happening.
     * Two runs then walk the same file together: both look up a barcode, neither has committed it
     * yet, and both create the product.
     *
     * A second run is now refused while one is in flight. The lock is taken here to stand in for
     * the run that is still going, because two genuinely simultaneous requests cannot be driven
     * from one test process.
     */
    public function test_a_second_upload_is_refused_while_one_is_still_running(): void
    {
        $lock = Cache::lock( 'ns-inventory-upload', 60 );

        $this->assertTrue( $lock->get(), 'The lock could not be taken, so this test proves nothing.' );

        try {
            $response = $this->postRows( [ $this->ladderRow( '69090135' ) ] );

            $response->assertSessionHas( 'error' );

            $this->assertStringContainsString(
                'ยังทำงานอยู่',
                (string) session( 'error' ),
                'The refusal does not tell the operator that a run is still going, which is the one thing they need to know.'
            );

            $this->assertCount(
                0,
                Product::where( 'barcode', $this->barcodePiece )->get(),
                'The refused upload still wrote to the catalogue, so the lock is not actually guarding the import.'
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * The lock must not outlive the request that took it, or one upload would block the shop for
     * fifteen minutes.
     */
    public function test_the_lock_is_released_when_the_upload_finishes(): void
    {
        $this->uploadRows( [ $this->ladderRow( '69090135' ) ] );

        $lock = Cache::lock( 'ns-inventory-upload', 10 );

        $this->assertTrue(
            $lock->get(),
            'The lock is still held after the upload returned, so no further import could ever run.'
        );

        $lock->release();
    }

    /**
     * Locking must not stop a normal second upload from correcting prices, which is the everyday
     * reason a shop uploads the same file again.
     */
    public function test_a_later_upload_still_updates_the_price(): void
    {
        $this->uploadRows( [ $this->ladderRow( '69090135' ) ] );

        $this->uploadRows( [ $this->ladderRow( '69090135', null, null, '99.00' ) ] );

        $product = Product::where( 'barcode', $this->barcodePiece )->first();

        $this->assertInstanceOf( Product::class, $product );

        $rows = ProductUnitQuantity::where( 'product_id', $product->id )->get();

        $picture = sprintf(
            'products=%s lockFree=%s | %s',
            Product::where( 'barcode', $this->barcodePiece )->pluck( 'id' )->implode( ',' ),
            Cache::lock( 'ns-inventory-upload', 5 )->get() ? 'yes' : 'NO (a lock leaked)',
            $rows->map( fn ( $r ): string => sprintf(
                'uq=%d unit=%s barcode=%s qty=%s price=%s',
                $r->id,
                $r->unit_id,
                $r->barcode,
                $r->quantity,
                $r->sale_price
            ) )->implode( ' | ' )
        );

        /**
         * One row per barcode. A second row carrying the same barcode would leave the till with two
         * prices for one scan, and whichever came back first would win.
         */
        $this->assertCount(
            1,
            $rows->where( 'barcode', $this->barcodePiece ),
            'The base barcode is on more than one unit row. Rows: ' . $picture
        );

        $base = $rows->firstWhere( 'barcode', $this->barcodePiece );

        $this->assertNotNull( $base, 'The base unit row is missing. Rows: ' . $picture );

        $this->assertSame(
            99.0,
            round( (float) $base->sale_price, 2 ),
            'A second upload did not update the price. Rows: ' . $picture
        );
    }

    /**
     * One row of the ladder layout. Both rows describe the same goods and differ only in the group
     * code, which is how the shop's export repeats a product.
     *
     * @return list<string>
     */
    private function ladderRow(
        string $stockCode,
        ?string $pieceBarcode = null,
        ?string $name = null,
        string $piecePrice = '65.00'
    ): array {
        return [
            $stockCode,
            $name ?? 'สินค้าทดสอบซ้ำ ขวด 710 มล.',
            '1',
            '2',
            '50.00',
            $pieceBarcode ?? $this->barcodePiece,
            $piecePrice,
            'ขวด',
            '',
            '',
            '',
            '',
            $pieceBarcode === null ? $this->barcodeCase : '',
            '12',
            '760.00',
            'หีบ',
            '10',
            'ขวด',
        ];
    }

    /**
     * Writes the rows to a CSV in the ladder layout and posts it to the upload endpoint.
     *
     * @param list<list<string>> $rows
     */
    private function uploadRows( array $rows ): void
    {
        $response = $this->postRows( $rows );

        /**
         * The endpoint redirects back with a summary either way, so a 500 here means the upload
         * itself broke rather than the assertions in the tests being wrong.
         */
        $this->assertContains(
            $response->status(),
            [ 200, 302 ],
            'The upload endpoint did not accept the file.'
        );

        /**
         * A refusal also redirects with a 302, so the status alone cannot tell a completed import
         * from one that was turned away. A completed import always flashes a summary, so that is
         * what is asserted. Read through the response rather than the session helper, which does
         * not see data flashed for the next request.
         */
        $session = $response->getSession();

        $this->assertTrue(
            $session->has( 'success' ),
            sprintf(
                'The import did not complete. upload #%d, status=%d, target=%s, error=%s, validation=%s, sessionKeys=%s',
                ++$this->uploadCount,
                $response->status(),
                (string) $response->headers->get( 'Location' ),
                (string) $session->get( 'error' ),
                $session->has( 'errors' )
                    ? json_encode( $session->get( 'errors' )->getBag( 'default' )->all(), JSON_UNESCAPED_UNICODE )
                    : 'none',
                implode( ',', array_keys( $session->all() ) )
            )
        );
    }

    /**
     * Posts the rows and hands back the response, so a test can assert on a refusal.
     *
     * @param list<list<string>> $rows
     */
    private function postRows( array $rows ): TestResponse
    {
        $header = [
            'รหัสสต็อก',
            'ชื่อสินค้า',
            'กลุ่มหลัก',
            'ย่่อย',
            'ราคาทุน',
            'บาร์โค้ดชิ้น',
            'ราคาชิ้น',
            'ชื่อหน่วยเล็ก',
            'บาร์โค้ดแพ็ค',
            'ชิ้นต่อแพ็ค',
            'ราคาแพ็ค',
            'ชื่อหน่วยกลาง',
            'บาร์โค้ดหีบ',
            'แพ็คต่อหีบ',
            'ราคาหีบ',
            'ชื่อหน่วยใหญ่',
            'จำนวนรับเข้า',
            'หน่วยที่นับ',
        ];

        $handle = fopen( 'php://temp', 'r+' );
        fputcsv( $handle, $header );

        foreach ( $rows as $row ) {
            fputcsv( $handle, $row );
        }

        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );

        $path = tempnam( sys_get_temp_dir(), 'nsupload' ) . '.csv';
        file_put_contents( $path, $csv );

        /**
         * The guard is named because the dashboard reads Auth::viaRemember(), which the token
         * guard does not implement, and because leaving it to the default made the second upload
         * of a test land unauthenticated.
         */
        $response = $this->actingAs( $this->actor(), 'web' )->post( 'dashboard/inventory/upload', [
            'csv_file' => new UploadedFile( $path, 'upload.csv', 'text/csv', null, true ),
            'branch_id' => $this->branch()->id,
            'stock_mode' => 'set',
        ] );

        @unlink( $path );

        return $response;
    }

    /**
     * The import must be allowed to outlast php.ini's 30 seconds.
     *
     * At roughly fifteen products a second, a few hundred lines exceed the default allowance and
     * the request is killed part way through, leaving the catalogue half updated and the operator
     * looking at a bare 500. The allowance is tied to the lock's lifetime so that a request is
     * never permitted to outlive the lock that is keeping a second import out.
     *
     * The process value is put back afterwards: raising it here is meant to prove the controller
     * asked for it, not to change how the rest of the suite runs.
     */
    public function test_the_import_is_given_more_time_than_php_allows_by_default(): void
    {
        $executionTimeBefore = (int) ini_get( 'max_execution_time' );
        $userAbortBefore = (int) ignore_user_abort();

        try {
            $this->uploadRows( [ $this->ladderRow( '69090135' ) ] );

            $this->assertSame(
                InventoryImportLock::SECONDS,
                (int) ini_get( 'max_execution_time' ),
                'The import runs under php.ini\'s default allowance, so a file of a few hundred'
                    . ' lines is killed part way through and the shop is left with half of it.'
            );

            $this->assertSame(
                1,
                (int) ignore_user_abort(),
                'The import stops when the browser gives up waiting, which cuts the file off'
                    . ' mid-way and leaves the catalogue partly updated.'
            );
        } finally {
            set_time_limit( $executionTimeBefore );
            ignore_user_abort( (bool) $userAbortBefore );
        }
    }

    /**
     * Removes the units and unit groups the upload created.
     *
     * Units are matched on the identifier the controller mints for them, "up-<group>-<hash>",
     * because Thai unit names slug to nothing and that prefix is the only thing that marks a unit
     * as this importer's work. Deleting them is enough on its own to restore the one-base-unit
     * invariant, even for a group another fixture has since adopted.
     *
     * A group is only removed once nothing is left pointing at it. If a fixture has attached its
     * own units to it, the group now belongs to that fixture, and dropping it would orphan those
     * units — trading one form of pollution for a worse one.
     */
    private function removeImportedUnits(): void
    {
        DB::table( 'nexopos_units' )
            ->where( 'id', '>', $this->unitWatermark )
            ->where( 'identifier', 'like', 'up-%' )
            ->delete();

        /**
         * Matched on the description the controller writes, not merely on being newer than the
         * watermark. Other tests in the same suite create unit groups too — an earlier version of
         * this cleanup removed an empty "Liquids" group belonging to another fixture, which left
         * later tests building orders against products with no usable unit and produced failures
         * that looked random.
         */
        $groups = DB::table( 'nexopos_units_groups' )
            ->where( 'id', '>', $this->unitGroupWatermark )
            ->where( 'description', 'like', 'สร้างจากไฟล์อัพโหลด%' )
            ->pluck( 'id' );

        foreach ( $groups as $groupId ) {
            $stillUsed = DB::table( 'nexopos_units' )->where( 'group_id', $groupId )->exists()
                || DB::table( 'nexopos_products' )->where( 'unit_group', $groupId )->exists();

            if ( ! $stillUsed ) {
                DB::table( 'nexopos_units_groups' )->where( 'id', $groupId )->delete();
            }
        }
    }

    private function actor(): User
    {
        if ( ! $this->actor instanceof User ) {
            $this->actor = $this->attemptGetAnyUserFromRole();
        }

        return $this->actor;
    }

    private function branch(): Branch
    {
        $branch = Branch::orderBy( 'id' )->first();

        if ( $branch instanceof Branch ) {
            return $branch;
        }

        $branch = new Branch;
        $branch->name = 'สาขาทดสอบการนำเข้าซ้ำ';
        $branch->code = 'DUP' . str_pad( (string) random_int( 0, 999 ), 3, '0', STR_PAD_LEFT );
        $branch->is_active = true;
        $branch->is_main = true;
        $branch->author_id = User::first()->id;
        $branch->save();

        $this->createdRows[] = [ 'nexopos_branches', $branch->id ];

        return $branch;
    }
}
