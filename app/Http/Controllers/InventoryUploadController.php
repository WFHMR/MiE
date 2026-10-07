<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\InventoryImportLock;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Loads a single branch export into stock and onto the till.
 *
 * This page previously read the file with a fixed layout: skip two lines, treat the third as
 * the column names. The shop's export carries one title line, so the first row of goods became
 * the header and every column lookup missed. Nothing was ever imported and each row failed with
 * "ไม่พบชื่อสินค้า" while the page reported success. Several column names were also spelt
 * differently from the file (บาร์โค๊ต for บาร์โค๊ด, คงเหลือ for จำนวน, หลัก for กลุ่มหลัก), the
 * rows carried no branch, no barcode and no VAT split, and an unpriced row was published as
 * available — which hands the goods over for nothing.
 *
 * What it does now, per row, is the same arithmetic the import command applies:
 *
 *  - A barcode already in the catalogue has its price and stock refreshed in place. Goods that
 *    sell through a unit ladder keep the ladder: a quantity stated in a larger unit is converted
 *    into base units and written to the base row, because stock lives on the base unit alone.
 *  - A barcode not yet known becomes a product with one unit, taken from the row's own หน่วยขาย,
 *    stocked with its own จำนวน.
 *  - A row priced at zero is loaded and withheld from sale rather than published.
 *
 * Nothing is deleted and no product is hidden that was on sale, so a mistaken upload costs a
 * price and a count, never the catalogue.
 */
class InventoryUploadController extends Controller
{
    private const VAT_RATE = 7.0;

    private const VAT_GROUP_NAME = 'ภาษีมูลค่าเพิ่ม 7%';

    /**
     * Header names as the exports actually spell them, including two files that disagree, and
     * the stray second tone mark in ย่่อย.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'stock' => [ 'รหัสสต็อก' ],
        'barcode' => [ 'รหัสบาร์โค๊ดสินค้า', 'รหัสบาร์โค๊ตสินค้า', 'บาร์โค้ด' ],
        'name' => [ 'ชื่อ/รายละเอียดสินค้า', 'ชื่อสินค้า' ],
        'pack' => [ 'บรรจุ' ],
        'saleUnit' => [ 'หน่วยขาย' ],
        'category' => [ 'กลุ่มหลัก', 'หลัก' ],
        'subCategory' => [ 'ย่่อย', 'ย่อย' ],
        'cost' => [ 'ราคาทุน' ],
        'sale' => [ 'ขาย (VAT)', 'ขาย(VAT)', 'ราคาขาย' ],
        'qty' => [ 'จำนวน', 'คงเหลือ' ],
    ];

    /**
     * The second layout: one row carries the whole unit ladder of one product.
     *
     * The branch export describes one unit per row, so a product sold as ชิ้น, แพ็ค and หีบ needs
     * three rows and a separate register file to say how they relate. This layout puts the
     * relationship in the row itself, which is how the shop already thinks about it: a case holds
     * so many packs, a pack holds so many pieces.
     *
     * A level with no barcode is a level the shop does not sell separately, and no row is made
     * for it.
     *
     * @var array<string, list<string>>
     */
    private const LADDER_COLUMNS = [
        'stock' => [ 'รหัสสต็อก' ],
        'name' => [ 'ชื่อสินค้า', 'ชื่อ/รายละเอียดสินค้า' ],
        'category' => [ 'กลุ่มหลัก', 'หลัก' ],
        'subCategory' => [ 'ย่่อย', 'ย่อย' ],
        'cost' => [ 'ราคาทุน' ],
        'costUnit' => [ 'หน่วยต้นทุน' ],
        'providerCode' => [ 'รหัสซัพพลายเออร์', 'รหัสซัพพลาย', 'รหัสผู้ขาย' ],
        'providerName' => [ 'ชื่อ/บริษัท', 'ชื่อซัพพลายเออร์' ],

        'pieceBarcode' => [ 'บาร์โค้ดชิ้น' ],
        'piecePrice' => [ 'ราคาชิ้น' ],
        'pieceName' => [ 'ชื่อหน่วยเล็ก' ],

        'packBarcode' => [ 'บาร์โค้ดแพ็ค' ],
        'packRatio' => [ 'ชิ้นต่อแพ็ค' ],
        'packPrice' => [ 'ราคาแพ็ค' ],
        'packName' => [ 'ชื่อหน่วยกลาง' ],

        'caseBarcode' => [ 'บาร์โค้ดหีบ' ],
        'caseRatio' => [ 'แพ็คต่อหีบ' ],
        'casePrice' => [ 'ราคาหีบ' ],
        'caseName' => [ 'ชื่อหน่วยใหญ่' ],

        'receiveQty' => [ 'จำนวนรับเข้า', 'จำนวน' ],
        'receiveUnit' => [ 'หน่วยที่นับ' ],
    ];

    /**
     * The third layout: goods the shop breaks open and weighs out itself.
     *
     * A sack of sugar holds ten kilos and is sold loose at a hundred grams, two hundred, half a
     * kilo and a kilo. The ladder layout above cannot describe that, because it offers three fixed
     * rungs — piece, pack, case — and this needs five. It also asks for each rung as a multiple of
     * the one below it, which for weights means working out that half a kilo is two and a half two
     * hundreds. Nobody weighing out sugar thinks that way, and a shop asked to do that arithmetic
     * by hand will get it wrong on the row where it matters.
     *
     * So a size here is stated as the number a scale would show — 100, 200, 500, 1000 — and the
     * conversion is worked out from the sizes themselves. The columns carrying those sizes are
     * numbered rather than named, so a product sold in nine sizes needs nine sets of columns and
     * no change to this file.
     *
     * @var array<string, list<string>>
     */
    private const DIVIDED_COLUMNS = [
        'stock' => [ 'รหัสสต็อก' ],
        'name' => [ 'ชื่อสินค้า', 'ชื่อ/รายละเอียดสินค้า' ],
        'category' => [ 'กลุ่มหลัก', 'หลัก' ],
        'subCategory' => [ 'ย่่อย', 'ย่อย' ],
        'cost' => [ 'ราคาทุน' ],

        /**
         * What the sizes are measured in — กรัม, มล., ใบ. It names the sizes on screen and lets a
         * delivery be counted in it, and it is never itself a unit the till sells.
         */
        'countUnit' => [ 'หน่วยนับ', 'ชื่อหน่วยนับ' ],

        'receiveQty' => [ 'จำนวนรับเข้า', 'จำนวน' ],
        'receiveUnit' => [ 'หน่วยที่นับ' ],
    ];

    /**
     * The numbered column series that carries one size.
     *
     * @var array<string, list<string>>
     */
    private const DIVIDED_LEVEL_COLUMNS = [
        'barcode' => [ 'บาร์โค้ด' ],
        'size' => [ 'ขนาด' ],
        'price' => [ 'ราคา' ],
        'unit' => [ 'ชื่อขนาด' ],
    ];

    /**
     * How far the numbered series is looked for. A shop that needs more only has to add the
     * columns; this is a stop so a malformed header cannot be scanned forever.
     */
    private const DIVIDED_MAX_LEVELS = 30;

    public function index()
    {
        return view( 'pages.dashboard.inventory.upload', [
            /**
             * Active only. A branch that has been switched off should not be a place goods can be
             * imported into, and offering it invites a whole file to be loaded onto a dead shelf.
             */
            'branches' => Branch::where( 'is_active', true )->orderBy( 'id' )->get(),
            'currentBranchId' => (int) ( session( 'selected_branch_id' ) ?: 0 ),
        ] );
    }

    /**
     * Answers a scanned barcode for the desk counting or receiving goods.
     *
     * Deliberately not the till's lookup. `ProductsController::searchUsingArgument` exists and resolves
     * a barcode to a product and a unit already, but it answers the question "can this be sold here",
     * and two of its rules are wrong for this page:
     *
     *   - it requires the product to be `onSale()`. Goods are received *before* they are priced, and
     *     the importer deliberately withholds a product priced at nothing so a scan at the till cannot
     *     give it away. Receiving a delivery of exactly those goods has to be possible.
     *   - it resolves the branch from the open till (`branchIdForCurrentTill`), because a register is
     *     bolted to one branch. This page has its own branch selector and the operator is not standing
     *     at a till; the branch they picked is the answer.
     *
     * So the resolution is repeated here against those rules instead of shared. What is *not* repeated
     * is the arithmetic: the figure is written by the same `applyCountedQuantity()` the CSV import uses.
     */
    public function scanLookup( Request $request, string $barcode )
    {
        ns()->restrict( 'nexopos.create.products' );

        $request->validate( [
            'branch_id' => [ 'required', 'integer', 'exists:nexopos_branches,id' ],
        ] );

        $barcode = trim( $barcode );
        $branchId = (int) $request->input( 'branch_id' );

        if ( $barcode === '' ) {
            return response()->json( [
                'status' => 'error',
                'message' => 'เครื่องอ่านไม่ได้ส่งบาร์โค้ดมา',
            ], 404 );
        }

        /**
         * Branchless rows are accepted alongside the branch's own, because a single store install
         * carries no branch on any row and excluding them would report the whole catalogue as missing.
         */
        $scopeToBranch = fn ( $query ) => $query->where(
            fn ( $scoped ) => $scoped->where( 'branch_id', $branchId )->orWhereNull( 'branch_id' )
        );

        $unitQuantity = ProductUnitQuantity::where( 'barcode', $barcode )
            ->where( $scopeToBranch )
            ->with( 'unit' )
            ->first();

        if ( ! $unitQuantity instanceof ProductUnitQuantity ) {
            $product = Product::where( 'barcode', $barcode )->first();

            if ( ! $product instanceof Product ) {
                return response()->json( [
                    'status' => 'error',
                    'message' => sprintf( 'ไม่พบสินค้าที่ใช้บาร์โค้ด %s — ถ้าเป็นของใหม่ ต้องสร้างสินค้าหรือนำเข้าด้วยไฟล์ก่อน', $barcode ),
                ], 404 );
            }

            $rows = ProductUnitQuantity::where( 'product_id', $product->id )
                ->where( $scopeToBranch )
                ->with( 'unit' )
                ->get();

            if ( $rows->isEmpty() ) {
                return response()->json( [
                    'status' => 'error',
                    'message' => sprintf( 'สินค้า "%s" ยังไม่มีแถวหน่วยในสาขานี้ จึงยังรับเข้าไม่ได้', $product->name ),
                ], 404 );
            }

            /**
             * A product's own barcode is the barcode of one of its units throughout this catalogue, so
             * that unit is what was scanned and no question needs asking. A single remaining row is
             * equally unambiguous. Anything else genuinely is ambiguous, and the operator picks.
             */
            $unitQuantity = $rows->firstWhere( 'barcode', $barcode );

            if ( ! $unitQuantity instanceof ProductUnitQuantity && $rows->count() === 1 ) {
                $unitQuantity = $rows->first();
            }

            if ( ! $unitQuantity instanceof ProductUnitQuantity ) {
                return response()->json( [
                    'status' => 'choose',
                    'message' => sprintf( 'สินค้า "%s" มีหลายหน่วย เลือกหน่วยที่ยิงมา', $product->name ),
                    'product' => [ 'id' => $product->id, 'name' => $product->name ],
                    'units' => $rows->map( fn ( ProductUnitQuantity $row ) => $this->scanLine( $row ) )->values(),
                ] );
            }
        }

        return response()->json( [
            'status' => 'success',
            'line' => $this->scanLine( $unitQuantity ),
        ] );
    }

    /**
     * One scannable unit, described the way the counting screen needs it.
     *
     * `stocksOn` is the part that matters and the part a spreadsheet never told anybody: where the goods
     * hold their stock on a base unit, a case scanned here does not move the case row, it moves the
     * piece row by the number of pieces in a case. Saying so on screen is the difference between an
     * operator trusting the figure and an operator counting the shelf again by hand.
     *
     * @return array<string, mixed>
     */
    private function scanLine( ProductUnitQuantity $unitQuantity ): array
    {
        $product = Product::find( $unitQuantity->product_id );
        $unit = $unitQuantity->unit ?: Unit::find( $unitQuantity->unit_id );

        $stocksOnUnitName = null;
        $factor = 1.0;

        $onHand = (float) $unitQuantity->quantity;
        $service = app( ProductService::class );
        $branchId = $unitQuantity->branch_id !== null ? (int) $unitQuantity->branch_id : null;
        $bearing = $service->resolveStockBearingUnit( (int) $unitQuantity->product_id, (int) $unitQuantity->unit_id, $branchId );

        if ( $bearing['unit_id'] !== (int) $unitQuantity->unit_id ) {
            $stocksOnUnitName = (string) Unit::findOrFail( $bearing['unit_id'] )->name;
            $factor = $bearing['factor'];
            $onHand = $service->getQuantity( (int) $unitQuantity->product_id, $bearing['unit_id'], $branchId ) / $factor;
        }

        return [
            'unit_quantity_id' => (int) $unitQuantity->id,
            'product_id' => (int) $unitQuantity->product_id,
            'product_name' => (string) ( $product?->name ?? '' ),
            'sku' => (string) ( $product?->sku ?? '' ),
            'barcode' => (string) ( $unitQuantity->barcode ?? '' ),
            'unit_id' => (int) $unitQuantity->unit_id,
            'unit_name' => (string) ( $unit?->name ?? '' ),
            'on_hand' => $onHand,
            'cost' => (float) $unitQuantity->cogs,
            'sale_price' => (float) $unitQuantity->sale_price,
            'stocks_on_unit_name' => $stocksOnUnitName,
            'stocks_on_factor' => $factor,
        ];
    }

    /**
     * Writes the lines the desk scanned, under the same lock and the same arithmetic as a file.
     *
     * One transaction per line, for the reason the file import has one per row: a bad line does not
     * throw away the rest of the count, and a till reading the shelf never sees a half written figure.
     *
     * The lock is shared with the CSV upload and the console import commands. A count being keyed in by
     * scanning is a write against the same rows a file would touch, so letting the two run together is
     * the race the lock exists to prevent.
     */
    public function scanApply( Request $request )
    {
        ns()->restrict( 'nexopos.create.products' );

        $request->validate( [
            'branch_id' => [ 'required', 'integer', 'exists:nexopos_branches,id' ],
            'stock_mode' => [ 'required', 'in:' . self::MODE_RECEIVE . ',' . self::MODE_SET ],
            'lines' => [ 'required', 'array', 'min:1' ],
            'lines.*.unit_quantity_id' => [ 'required', 'integer' ],
            'lines.*.quantity' => [ 'required', 'numeric' ],
        ], [
            'stock_mode.required' => 'ต้องเลือกก่อนว่ารอบนี้คือการรับสินค้าเข้า หรือการตั้งยอดคงเหลือใหม่',
            'lines.required' => 'ยังไม่มีรายการที่ยิงเข้ามา',
        ] );

        $importLock = app( InventoryImportLock::class );
        $lock = $importLock->attempt();

        if ( $lock === null ) {
            return response()->json( [
                'status' => 'error',
                'message' => $importLock->messageForOperator(),
            ], 423 );
        }

        try {
            return $this->runScanApply( $request );
        } finally {
            $lock->release();
        }
    }

    private function runScanApply( Request $request )
    {
        $branchId = (int) $request->input( 'branch_id' );
        $mode = (string) $request->input( 'stock_mode' );
        $authorId = (int) ( auth()->id() ?: 1 );

        $applied = 0;
        $skipped = [];

        foreach ( (array) $request->input( 'lines' ) as $index => $line ) {
            $unitQuantityId = (int) ( $line[ 'unit_quantity_id' ] ?? 0 );
            $quantity = (float) ( $line[ 'quantity' ] ?? 0 );

            try {
                /**
                 * A quantity of nothing is a line the operator scanned and then emptied. In receive mode
                 * it would move nothing anyway, but in set mode it would write a zero and empty the
                 * shelf, so it is refused in both rather than silently meaning different things.
                 */
                if ( $quantity <= 0 ) {
                    throw new \RuntimeException( 'จำนวนต้องมากกว่า 0' );
                }

                DB::transaction( function () use ( $unitQuantityId, $branchId, $quantity, $authorId, $mode ): void {
                    $unitQuantity = ProductUnitQuantity::where( 'id', $unitQuantityId )
                        ->where( fn ( $query ) => $query->where( 'branch_id', $branchId )->orWhereNull( 'branch_id' ) )
                        ->first();

                    /**
                     * Checked against the branch that was submitted, not trusted from the payload. The
                     * row id travels through the browser, and a row belonging to another branch must not
                     * be writable by changing a number in a request.
                     */
                    if ( ! $unitQuantity instanceof ProductUnitQuantity ) {
                        throw new \RuntimeException( 'ไม่พบแถวหน่วยนี้ในสาขาที่เลือก' );
                    }

                    $product = Product::find( $unitQuantity->product_id );

                    if ( ! $product instanceof Product ) {
                        throw new \RuntimeException( 'แถวหน่วยนี้ไม่มีสินค้า' );
                    }

                    $this->applyCountedQuantity(
                        product: $product,
                        unitQuantity: $unitQuantity,
                        counted: $quantity,
                        unitPrice: (float) $unitQuantity->cogs,
                        authorId: $authorId,
                        mode: $mode,
                        source: self::SOURCE_SCAN
                    );
                } );

                $applied++;
            } catch ( \Throwable $e ) {
                $skipped[] = sprintf( 'รายการที่ %d: %s', (int) $index + 1, $e->getMessage() );

                Log::error( 'inventory scan line failed', [
                    'unit_quantity_id' => $unitQuantityId,
                    'branch_id' => $branchId,
                    'error' => $e->getMessage(),
                ] );
            }
        }

        $branchName = Branch::find( $branchId )?->name ?? (string) $branchId;

        return response()->json( [
            'status' => $applied > 0 ? 'success' : 'error',
            'applied' => $applied,
            'skipped' => $skipped,
            'message' => sprintf(
                '%s — สาขา %s — บันทึก %d รายการ%s',
                $mode === self::MODE_RECEIVE ? 'รับสินค้าเข้า (บวกเพิ่มจากยอดเดิม)' : 'อัปเดตสต๊อก (ตั้งยอดคงเหลือใหม่)',
                $branchName,
                $applied,
                $skipped === [] ? '' : sprintf( ' — ข้าม %d รายการ', count( $skipped ) )
            ),
        ], $applied > 0 ? 200 : 422 );
    }

    /**
     * The two things a stock figure in a file can mean.
     *
     * RECEIVE adds the figure to what is already on hand, which is what a delivery note says:
     * sixty eight yesterday plus thirty seven received today is a hundred and five.
     *
     * SET replaces it, which is what a stock count says: whatever the system believed, the
     * shelf holds this many.
     *
     * Reading a delivery note as a count loses the goods already on the shelf. Reading a count
     * as a delivery doubles them. Neither can be inferred from the file, so the operator picks,
     * and there is deliberately no default.
     */
    private const MODE_RECEIVE = 'receive';

    private const MODE_SET = 'set';

    public function upload( Request $request )
    {
        ns()->restrict( 'nexopos.create.products' );

        /**
         * A CSV out of Excel arrives as application/vnd.ms-excel often enough that mimes:csv
         * alone rejected the shop's own file, so the extension is what is checked.
         */
        $request->validate( [
            'csv_file' => [ 'required', 'file', 'max:20480' ],
            'branch_id' => [ 'required', 'integer', 'exists:nexopos_branches,id' ],
            'stock_mode' => [ 'required', 'in:' . self::MODE_RECEIVE . ',' . self::MODE_SET ],
        ], [
            'stock_mode.required' => 'ต้องเลือกก่อนว่าไฟล์นี้คือการรับสินค้าเข้า หรือการตั้งยอดคงเหลือใหม่',
        ] );

        /**
         * Taken after validation so a malformed request cannot hold the lock, and released in a
         * finally so every exit from the import frees it, including the early returns for an
         * unreadable file.
         *
         * The lock lives in a service shared with the console import commands, because the upload
         * page is only one of the doors into the catalogue and a command run while someone is
         * uploading races in exactly the same way.
         *
         * Refused rather than queued. A large file can take minutes, and holding this request open
         * until the other one finishes is how the browser gave up in the first place.
         */
        $importLock = app( InventoryImportLock::class );
        $lock = $importLock->attempt();

        if ( $lock === null ) {
            return redirect()->back()->with( 'error', $importLock->messageForOperator() );
        }

        /**
         * The shop's file is longer than PHP's patience.
         *
         * php.ini allows a request 30 seconds. The importer writes roughly fifteen products a
         * second, so a file of a few hundred lines is killed part way through: the rows already
         * committed stay, the rest never arrive, and the operator is shown a bare 500 with no way
         * to tell how much got in. That is what happened at 12:12 on 15 September, where 447
         * products landed and the run then died.
         *
         * The allowance is tied to the lock's own lifetime so the two cannot disagree: while this
         * request is permitted to run, its lock is still valid, and no second import can start.
         */
        set_time_limit( InventoryImportLock::SECONDS );

        /**
         * Finish the file even if the operator's browser gives up waiting. An abandoned request
         * that is cut off mid-file leaves the catalogue half updated, and the access log shows the
         * browser doing exactly that (a 499 at 11:22).
         */
        ignore_user_abort( true );

        /**
         * A fatal error does not run finally.
         *
         * Exhausting the time or memory limit is a fatal error, not an exception, so the release
         * below is skipped and the lock survives for its full term. The retry 24 seconds after the
         * 12:12 failure was refused for this reason, and the operator was shut out for the next
         * fifteen minutes while nothing at all was running.
         *
         * Releasing on shutdown covers that. Releasing twice is harmless, so the finally below
         * stays as the ordinary path.
         */
        register_shutdown_function( static function () use ( $lock ): void {
            $lock->release();
        } );

        try {
            return $this->runImport( $request );
        } finally {
            $lock->release();
        }
    }

    /**
     * The import itself, run with the lock above held.
     *
     * Split out so the lock can be released on every path without repeating the release at each
     * of the early returns below.
     */
    private function runImport( Request $request )
    {
        $mode = (string) $request->input( 'stock_mode' );

        $extension = strtolower( (string) $request->file( 'csv_file' )->getClientOriginalExtension() );

        if ( ! in_array( $extension, [ 'csv', 'txt' ], true ) ) {
            return redirect()->back()->with( 'error', 'ต้องเป็นไฟล์ .csv หรือ .txt เท่านั้น' );
        }

        $branchId = (int) $request->input( 'branch_id' );

        try {
            $rows = $this->readRows( $request->file( 'csv_file' )->getRealPath() );
        } catch ( \Throwable $e ) {
            return redirect()->back()->with( 'error', 'อ่านไฟล์ไม่ได้: ' . $e->getMessage() );
        }

        if ( $rows === [] ) {
            /**
             * The old wording named the columns of the one-unit-per-row export, which are not the
             * columns of the standard template staff are now given. Someone who uploads the template
             * before filling it in — the commonest way to reach this line — was told to look for
             * headings their file does not contain, and went looking for a fault that was not there.
             */
            return redirect()->back()->with( 'error', 'ไม่พบแถวสินค้าในไฟล์ — ถ้าเพิ่งดาวน์โหลดแม่แบบมา ให้กรอกรายการสินค้าใต้หัวตารางก่อนแล้วอัพใหม่ ถ้ากรอกแล้ว ให้ตรวจว่าหัวตารางยังมีคอลัมน์ ชื่อสินค้า และ บาร์โค้ดชิ้น อยู่ครบ (ห้ามลบหรือเปลี่ยนชื่อหัวตาราง)' );
        }

        $authorId = (int) ( auth()->id() ?: 1 );

        $created = 0;
        $updated = 0;
        $withheld = 0;
        $skipped = [];

        $this->ensureVatGroup( $authorId );

        foreach ( $rows as $row ) {
            /**
             * One transaction per row. A file with a bad line in the middle then loads
             * everything else, and a till reading the catalogue at the same time never sees a
             * half written product.
             */
            try {
                DB::transaction( function () use ( $row, $branchId, $authorId, $mode, &$created, &$updated, &$withheld ): void {
                    $outcome = $this->importRow( $row, $branchId, $authorId, $mode );

                    match ( $outcome ) {
                        'created' => $created++,
                        'updated' => $updated++,
                        default => null,
                    };

                    if ( (float) $row[ 'sale' ] <= 0 ) {
                        $withheld++;
                    }
                } );
            } catch ( \Throwable $e ) {
                $skipped[] = sprintf( 'บรรทัด %d (%s): %s', $row[ 'line' ], $row[ 'barcode' ], $e->getMessage() );

                Log::error( 'inventory upload row failed', [
                    'line' => $row[ 'line' ],
                    'barcode' => $row[ 'barcode' ],
                    'error' => $e->getMessage(),
                ] );
            }
        }

        $branchName = Branch::find( $branchId )?->name ?? (string) $branchId;

        $summary = sprintf(
            '%s — สาขา %s — สร้างใหม่ %d, อัปเดต %d รายการ',
            $mode === self::MODE_RECEIVE ? 'รับสินค้าเข้า (บวกเพิ่มจากยอดเดิม)' : 'อัปเดตสต๊อก (ตั้งยอดคงเหลือใหม่)',
            $branchName,
            $created,
            $updated
        );

        $response = redirect()->back()->with( 'success', $summary );

        if ( $withheld > 0 ) {
            $response->with( 'warning', sprintf(
                'มี %d แถวที่ราคาขายเป็น 0 — นำเข้าแล้วแต่ยังไม่เปิดขาย เพื่อไม่ให้ยิงบาร์โค้ดแล้วได้ของฟรี ใส่ราคาในไฟล์แล้วอัพใหม่ได้เลย',
                $withheld
            ) );
        }

        if ( $skipped !== [] ) {
            $response->with( 'error', 'ข้ามไป ' . count( $skipped ) . ' แถว: ' . implode( ' | ', array_slice( $skipped, 0, 5 ) ) );
        }

        return $response;
    }

    /**
     * Finds the header wherever it sits and returns the rows keyed by meaning.
     *
     * The exports put a title line above the header, and one of them puts two. Locating the
     * header by its own content rather than by counting lines is what makes both readable, and
     * is what the fixed offset got wrong.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Returns a path whose contents are UTF-8, converting the file first if it is not.
     *
     * Excel on a Thai copy of Windows saves "CSV (Comma delimited)" as Windows-874, not UTF-8. Every
     * Thai product name, category and unit name in such a file arrives as unreadable bytes, and because
     * the importer keys products by barcode the damage is not obvious: the goods load, the till scans
     * them, and the shelf label reads as nonsense. Telling staff to pick the UTF-8 option does not
     * survive contact with a busy afternoon, so the file is converted here instead.
     *
     * A file that is already valid UTF-8 is left untouched, so nothing that worked before changes.
     */
    private function utf8Path( string $path ): string
    {
        $contents = file_get_contents( $path );

        if ( $contents === false || $contents === '' ) {
            return $path;
        }

        if ( mb_check_encoding( $contents, 'UTF-8' ) ) {
            return $path;
        }

        /**
         * iconv rather than mb_convert_encoding: this build of mbstring carries no Thai encoding at all
         * — mb_list_encodings() names none of Windows-874, TIS-620 or ISO-8859-11 — and asking it for one
         * raises a ValueError rather than returning false. iconv converts all three correctly.
         *
         * Windows-874 is what Excel writes on a Thai system. TIS-620 is the same repertoire under its
         * older name and is tried second so a file from an older tool is still read rather than rejected.
         */
        foreach ( [ 'WINDOWS-874', 'TIS-620' ] as $encoding ) {
            $converted = @iconv( $encoding, 'UTF-8', $contents );

            if ( is_string( $converted ) && $converted !== '' && mb_check_encoding( $converted, 'UTF-8' ) ) {
                $temporary = tempnam( sys_get_temp_dir(), 'ns-csv-' );

                if ( $temporary === false ) {
                    return $path;
                }

                file_put_contents( $temporary, $converted );

                return $temporary;
            }
        }

        /**
         * Nothing recognised it. The original is handed back so the reader reports what it finds
         * rather than this method deciding the file is unusable.
         */
        return $path;
    }

    private function readRows( string $path ): array
    {
        $handle = fopen( $this->utf8Path( $path ), 'r' );

        if ( $handle === false ) {
            throw new \RuntimeException( 'เปิดไฟล์ไม่ได้' );
        }

        $lines = [];

        while ( ( $line = fgetcsv( $handle ) ) !== false ) {
            $lines[] = $line;
        }

        fclose( $handle );

        /**
         * Excel writes a byte order mark, which lands inside the first cell and stops it matching
         * a column name. It only bites when the header is the very first line — which is exactly
         * what happens when someone deletes the guidance lines off the template, as the template
         * invites them to.
         */
        if ( isset( $lines[ 0 ][ 0 ] ) && str_starts_with( (string) $lines[ 0 ][ 0 ], "\xEF\xBB\xBF" ) ) {
            $lines[ 0 ][ 0 ] = substr( (string) $lines[ 0 ][ 0 ], 3 );
        }

        /**
         * The divided layout is looked for before the other two because its numbered columns
         * (ขนาด1, บาร์โค้ด1) appear in neither of them, so a match here is unambiguous and the
         * files already in use keep loading exactly as they did.
         */
        foreach ( $lines as $index => $line ) {
            $levels = $this->mapDividedLevels( $line );

            if ( $levels === [] ) {
                continue;
            }

            $candidate = $this->mapColumns( $line, self::DIVIDED_COLUMNS );

            if ( isset( $candidate[ 'name' ] ) ) {
                return $this->readDividedRows( $lines, $index, $candidate, $levels );
            }
        }

        /**
         * The ladder layout is looked for next. Its header carries at least one of the level
         * barcode columns, which the one-unit-per-row export never has, so the two cannot be
         * confused and the older files keep loading exactly as before.
         */
        foreach ( $lines as $index => $line ) {
            $candidate = $this->mapColumns( $line, self::LADDER_COLUMNS );

            $hasLevel = isset( $candidate[ 'pieceBarcode' ] )
                || isset( $candidate[ 'packBarcode' ] )
                || isset( $candidate[ 'caseBarcode' ] );

            if ( $hasLevel && isset( $candidate[ 'name' ] ) ) {
                return $this->readLadderRows( $lines, $index, $candidate );
            }
        }

        $headerIndex = null;
        $map = [];

        foreach ( $lines as $index => $line ) {
            $candidate = $this->mapColumns( $line );

            if ( isset( $candidate[ 'barcode' ], $candidate[ 'name' ] ) ) {
                $headerIndex = $index;
                $map = $candidate;

                break;
            }
        }

        if ( $headerIndex === null ) {
            throw new \RuntimeException( 'ไม่พบหัวตาราง' );
        }

        $rows = [];

        foreach ( array_slice( $lines, $headerIndex + 1 ) as $offset => $line ) {
            $barcode = trim( (string) ( $line[ $map[ 'barcode' ] ] ?? '' ) );
            $name = trim( (string) ( $line[ $map[ 'name' ] ] ?? '' ) );

            if ( $barcode === '' || $name === '' ) {
                continue;
            }

            /**
             * A spreadsheet turns a long barcode into 2.00E+66 and the digits are gone. Such a
             * row cannot be scanned or repaired here, so it is refused rather than loaded under
             * a code that does not exist.
             */
            if ( preg_match( '/^\d+(\.\d+)?E\+\d+$/i', $barcode ) === 1 ) {
                continue;
            }

            $pack = $this->toFloat( $line[ $map[ 'pack' ] ?? -1 ] ?? '1' );

            $rows[] = [
                'line' => $headerIndex + $offset + 2,
                'stock' => trim( (string) ( $line[ $map[ 'stock' ] ?? -1 ] ?? '' ) ),
                'barcode' => $barcode,
                'name' => $name,
                'pack' => $pack > 0 ? $pack : 1.0,
                'saleUnit' => trim( (string) ( $line[ $map[ 'saleUnit' ] ?? -1 ] ?? '' ) ) ?: 'ชิ้น',
                'category' => trim( (string) ( $line[ $map[ 'category' ] ?? -1 ] ?? '' ) ),
                'subCategory' => trim( (string) ( $line[ $map[ 'subCategory' ] ?? -1 ] ?? '' ) ),
                'cost' => $this->toFloat( $line[ $map[ 'cost' ] ?? -1 ] ?? '0' ),
                'sale' => $this->toFloat( $line[ $map[ 'sale' ] ?? -1 ] ?? '0' ),
                'qty' => $this->toFloat( $line[ $map[ 'qty' ] ?? -1 ] ?? '0' ),
            ];
        }

        return $rows;
    }

    /**
     * Turns one row of the ladder layout into the levels it describes, smallest first.
     *
     * A quantity column says how many of the next smaller level that is actually present the
     * unit holds, which is how a carton is labelled: this case holds four packs, this pack holds
     * twelve pieces. When the middle level is left out the case quantity is read against the
     * smallest level present instead, and the resolved figure is reported back so it can be
     * checked rather than assumed.
     *
     * @param  list<array<int, string|null>> $lines
     * @param  array<string, int>            $map
     * @return list<array<string, mixed>>
     */
    private function readLadderRows( array $lines, int $headerIndex, array $map ): array
    {
        $cell = function ( array $line, string $key ) use ( $map ): string {
            return trim( (string) ( $line[ $map[ $key ] ?? -1 ] ?? '' ) );
        };

        $rows = [];

        foreach ( array_slice( $lines, $headerIndex + 1 ) as $offset => $line ) {
            $name = $cell( $line, 'name' );

            if ( $name === '' ) {
                continue;
            }

            /**
             * A row the file got wrong is set aside with its reason and reported at the end,
             * rather than stopping the file: on a catalogue of several hundred lines, one typo
             * would otherwise block every other product from loading.
             */
            try {
                $rows[] = $this->readLadderRow( $line, $cell, $name, $headerIndex + $offset + 2 );
            } catch ( \Throwable $e ) {
                $rows[] = [
                    'line' => $headerIndex + $offset + 2,
                    'ladder' => true,
                    'name' => $name,
                    'barcode' => '',
                    'sale' => 0.0,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  callable(array<int, string|null>, string): string $cell
     * @return array<string, mixed>
     */
    private function readLadderRow( array $line, callable $cell, string $name, int $lineNumber ): array
    {

        $levels = [];

        /**
         * Smallest first, because the smallest level present becomes the unit the stock is
         * counted against.
         */
        $declared = [
            [ 'piece', $cell( $line, 'pieceBarcode' ), $cell( $line, 'pieceName' ) ?: 'ชิ้น', 1.0, $this->toFloat( $cell( $line, 'piecePrice' ) ) ],
            [ 'pack', $cell( $line, 'packBarcode' ), $cell( $line, 'packName' ) ?: 'แพ็ค', $this->toFloat( $cell( $line, 'packRatio' ) ), $this->toFloat( $cell( $line, 'packPrice' ) ) ],
            [ 'case', $cell( $line, 'caseBarcode' ), $cell( $line, 'caseName' ) ?: 'หีบ', $this->toFloat( $cell( $line, 'caseRatio' ) ), $this->toFloat( $cell( $line, 'casePrice' ) ) ],
        ];

        $absolute = 0.0;

        foreach ( $declared as [ $key, $barcode, $unitName, $relative, $price ] ) {
            if ( $barcode === '' ) {
                continue;
            }

            if ( preg_match( '/^\d+(\.\d+)?E\+\d+$/i', $barcode ) === 1 ) {
                continue;
            }

            if ( $absolute === 0.0 ) {
                /**
                 * The first level present is the base: it holds the stock and its ratio is
                 * one whatever the file wrote in its quantity column.
                 */
                $absolute = 1.0;
            } else {
                if ( $relative < 1 ) {
                    throw new \RuntimeException( sprintf(
                        'หน่วย %s มีบาร์โค้ดแต่ไม่ได้บอกว่าเท่ากับกี่หน่วยเล็ก',
                        $unitName
                    ) );
                }

                $absolute *= $relative;

                if ( abs( $absolute - round( $absolute ) ) > 0.0001 ) {
                    throw new \RuntimeException( sprintf(
                        'อัตราแปลงของหน่วย %s ไม่ลงตัว ได้ %s',
                        $unitName,
                        $absolute
                    ) );
                }

                $absolute = round( $absolute );
            }

            $levels[] = [
                'key' => $key,
                'barcode' => $barcode,
                'unit' => $unitName,
                'value' => $absolute,
                'sale' => $price,
            ];
        }

        if ( $levels === [] ) {
            throw new \RuntimeException( 'ไม่มีบาร์โค้ดในแถวนี้เลย' );
        }

        $barcodes = array_column( $levels, 'barcode' );

        if ( count( $barcodes ) !== count( array_unique( $barcodes ) ) ) {
            throw new \RuntimeException( 'ใช้บาร์โค้ดเดียวกันกับหลายหน่วยในแถวเดียว' );
        }

        return [
            'line' => $lineNumber,
            'ladder' => true,
            'stock' => $cell( $line, 'stock' ),
            'name' => $name,
            'category' => $cell( $line, 'category' ),
            'subCategory' => $cell( $line, 'subCategory' ),
            'cost' => $this->toFloat( $cell( $line, 'cost' ) ),
            'costUnit' => $cell( $line, 'costUnit' ),
            'providerCode' => $cell( $line, 'providerCode' ),
            'providerName' => $cell( $line, 'providerName' ),
            'levels' => $levels,
            'qty' => $this->toFloat( $cell( $line, 'receiveQty' ) ),
            'receiveUnit' => $cell( $line, 'receiveUnit' ),
            'barcode' => $levels[ 0 ][ 'barcode' ],
            'sale' => $levels[ 0 ][ 'sale' ],
        ];

    }

    /**
     * Finds the numbered size columns in a header line.
     *
     * The comparison is exact rather than by substring on purpose: ชื่อขนาด1 contains ขนาด1, and a
     * loose match would read the name column as the size and every product would come out weighing
     * nothing.
     *
     * A set counts as present once its ขนาด column is found, because that is the one figure the
     * layout cannot work without.
     *
     * @param  array<int, string|null>        $line
     * @return array<int, array<string, int>> level number => meaning => column index
     */
    private function mapDividedLevels( array $line ): array
    {
        $cells = [];

        foreach ( $line as $index => $cell ) {
            $cell = trim( (string) $cell );

            if ( $cell !== '' && ! isset( $cells[ $cell ] ) ) {
                $cells[ $cell ] = $index;
            }
        }

        $levels = [];

        for ( $number = 1; $number <= self::DIVIDED_MAX_LEVELS; $number++ ) {
            $found = [];

            foreach ( self::DIVIDED_LEVEL_COLUMNS as $meaning => $names ) {
                foreach ( $names as $name ) {
                    if ( isset( $cells[ $name . $number ] ) ) {
                        $found[ $meaning ] = $cells[ $name . $number ];

                        break;
                    }
                }
            }

            if ( ! isset( $found[ 'size' ] ) ) {
                continue;
            }

            $levels[ $number ] = $found;
        }

        return $levels;
    }

    /**
     * @param  list<array<int, string|null>>  $lines
     * @param  array<string, int>             $map
     * @param  array<int, array<string, int>> $levelMap
     * @return list<array<string, mixed>>
     */
    private function readDividedRows( array $lines, int $headerIndex, array $map, array $levelMap ): array
    {
        $cell = function ( array $line, string $key ) use ( $map ): string {
            return trim( (string) ( $line[ $map[ $key ] ?? -1 ] ?? '' ) );
        };

        $rows = [];

        foreach ( array_slice( $lines, $headerIndex + 1 ) as $offset => $line ) {
            $name = $cell( $line, 'name' );

            if ( $name === '' ) {
                continue;
            }

            /**
             * A row the file got wrong is set aside with its reason and reported at the end, so one
             * mistyped size does not stop the other several hundred products from loading.
             */
            try {
                $rows[] = $this->readDividedRow( $line, $cell, $levelMap, $name, $headerIndex + $offset + 2 );
            } catch ( \Throwable $e ) {
                $rows[] = [
                    'line' => $headerIndex + $offset + 2,
                    'ladder' => true,
                    'name' => $name,
                    'barcode' => '',
                    'sale' => 0.0,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $rows;
    }

    /**
     * Turns one row of the divided layout into the sizes it describes.
     *
     * The smallest size carrying a barcode holds the stock, and every other size is a label that
     * converts into it. Conversions have to land on whole numbers of that size, because a figure
     * like two and a half would drift the count on every sale and there is no such thing as selling
     * half of the smallest bag the shop makes up.
     *
     * A size with no barcode is not sold, but it is still worth stating: it is how a delivery gets
     * counted. The sack is the thing the storeman receives and the thing he never scans.
     *
     * @param  array<int, string|null>        $line
     * @param  array<int, array<string, int>> $levelMap
     * @return array<string, mixed>
     */
    private function readDividedRow( array $line, callable $cell, array $levelMap, string $name, int $lineNumber ): array
    {
        $countUnit = $cell( $line, 'countUnit' );

        $at = function ( array $columns, string $meaning ) use ( $line ): string {
            return trim( (string) ( $line[ $columns[ $meaning ] ?? -1 ] ?? '' ) );
        };

        $sellable = [];
        $countingOnly = [];

        foreach ( $levelMap as $number => $columns ) {
            $barcode = $at( $columns, 'barcode' );
            $size = $this->toFloat( $at( $columns, 'size' ) );
            $price = $this->toFloat( $at( $columns, 'price' ) );
            $unitName = $at( $columns, 'unit' );

            if ( $barcode === '' && $size <= 0 ) {
                continue;
            }

            if ( $size <= 0 ) {
                throw new \RuntimeException( sprintf(
                    'ชุดที่ %d มีบาร์โค้ดแต่ไม่ได้กรอกขนาด',
                    $number
                ) );
            }

            /**
             * A spreadsheet turns a long barcode into 2.00E+66 and the digits are gone. The size is
             * still usable for counting, so the level is kept as a counting size rather than
             * dropped, but it is never given a scannable row under a code that does not exist.
             */
            if ( $barcode !== '' && preg_match( '/^\d+(\.\d+)?E\+\d+$/i', $barcode ) === 1 ) {
                $barcode = '';
            }

            $level = [
                'key' => 'lv' . $number,
                'barcode' => $barcode,
                'unit' => $unitName !== '' ? $unitName : $this->dividedUnitName( $size, $countUnit ),
                'size' => $size,
                'sale' => $price,
            ];

            if ( $barcode === '' ) {
                $countingOnly[] = $level;

                continue;
            }

            $sellable[] = $level;
        }

        if ( $sellable === [] ) {
            throw new \RuntimeException( 'ไม่มีชุดใดในแถวนี้กรอกบาร์โค้ด จึงไม่มีหน่วยที่ขายได้' );
        }

        usort( $sellable, static fn ( array $a, array $b ): int => $a[ 'size' ] <=> $b[ 'size' ] );

        $baseSize = (float) $sellable[ 0 ][ 'size' ];

        if ( $baseSize <= 0 ) {
            throw new \RuntimeException( 'ขนาดที่เล็กที่สุดต้องมากกว่าศูนย์' );
        }

        $levels = [];

        foreach ( $sellable as $index => $level ) {
            $value = (float) $level[ 'size' ] / $baseSize;

            if ( abs( $value - round( $value ) ) > 0.0001 ) {
                throw new \RuntimeException( sprintf(
                    'ขนาด %s หารด้วยขนาดเล็กสุด %s ไม่ลงตัว ได้ %s — ขนาดทุกช่องต้องเป็นจำนวนเท่าของขนาดเล็กสุด',
                    $this->trimNumber( (float) $level[ 'size' ] ),
                    $this->trimNumber( $baseSize ),
                    $this->trimNumber( $value )
                ) );
            }

            $level[ 'value' ] = round( $value );
            $levels[] = $level;
        }

        /**
         * The counting sizes go on the end. Their ratio does not have to be whole because nothing
         * is ever sold in them: receiving ten thousand grams against a hundred gram bag is a
         * hundred bags whether or not a gram divides into one.
         */
        foreach ( $countingOnly as $level ) {
            $level[ 'value' ] = (float) $level[ 'size' ] / $baseSize;
            $levels[] = $level;
        }

        $barcodes = array_values( array_filter( array_column( $levels, 'barcode' ) ) );

        if ( count( $barcodes ) !== count( array_unique( $barcodes ) ) ) {
            throw new \RuntimeException( 'ใช้บาร์โค้ดเดียวกันกับหลายขนาดในแถวเดียว' );
        }

        $names = array_column( $levels, 'unit' );

        if ( count( $names ) !== count( array_unique( $names ) ) ) {
            throw new \RuntimeException( 'ตั้งชื่อขนาดซ้ำกันในแถวเดียว ทำให้บอกไม่ได้ว่ารับเข้าเป็นขนาดไหน' );
        }

        return [
            'line' => $lineNumber,
            'ladder' => true,
            'divided' => true,
            'stock' => $cell( $line, 'stock' ),
            'name' => $name,
            'category' => $cell( $line, 'category' ),
            'subCategory' => $cell( $line, 'subCategory' ),
            'cost' => $this->toFloat( $cell( $line, 'cost' ) ),
            'countUnit' => $countUnit,
            'baseSize' => $baseSize,
            'levels' => $levels,
            'qty' => $this->toFloat( $cell( $line, 'receiveQty' ) ),
            'receiveUnit' => $cell( $line, 'receiveUnit' ),
            'barcode' => $levels[ 0 ][ 'barcode' ],
            'sale' => $levels[ 0 ][ 'sale' ],
        ];
    }

    /**
     * The name a size is shown under when the file does not give it one: the figure followed by
     * what it is measured in, which is how the shelf label reads anyway.
     */
    private function dividedUnitName( float $size, string $countUnit ): string
    {
        /**
         * No thousands separator. The name generated here is the same text the operator has to type
         * into หน่วยที่นับ to count a delivery in it, and a comma inside a field of a comma
         * separated file is a trap laid for whoever fills the next one in.
         */
        $formatted = number_format( $size, 2, '.', '' );
        $number = str_contains( $formatted, '.' ) ? rtrim( rtrim( $formatted, '0' ), '.' ) : $formatted;

        return trim( $countUnit ) !== ''
            ? $number . ' ' . trim( $countUnit )
            : $number;
    }

    /**
     * @param  array<int, string|null>     $line
     * @param  array<string, list<string>> $columns
     * @return array<string, int>          meaning => column index
     */
    private function mapColumns( array $line, array $columns = self::COLUMNS ): array
    {
        $map = [];

        foreach ( $line as $index => $cell ) {
            $cell = trim( (string) $cell );

            if ( $cell === '' ) {
                continue;
            }

            foreach ( $columns as $meaning => $names ) {
                if ( isset( $map[ $meaning ] ) ) {
                    continue;
                }

                if ( in_array( $cell, $names, true ) ) {
                    $map[ $meaning ] = $index;

                    break;
                }
            }
        }

        return $map;
    }

    private function toFloat( ?string $value ): float
    {
        return (float) str_replace( [ ',', ' ' ], '', (string) $value );
    }

    /**
     * @param  array<string, mixed> $row
     * @return string               created|updated
     */
    private function importRow( array $row, int $branchId, int $authorId, string $mode ): string
    {
        if ( ! empty( $row[ 'error' ] ) ) {
            throw new \RuntimeException( (string) $row[ 'error' ] );
        }

        if ( ! empty( $row[ 'ladder' ] ) ) {
            return $this->importLadderRow( $row, $branchId, $authorId, $mode );
        }

        $existing = ProductUnitQuantity::where( 'barcode', $row[ 'barcode' ] )
            ->where( 'branch_id', $branchId )
            ->first();

        if ( $existing instanceof ProductUnitQuantity ) {
            $this->refreshExisting( $existing, $row, $authorId, $mode );

            return 'updated';
        }

        /**
         * A barcode the catalogue does not know has nothing to add to, so both modes agree:
         * the figure in the file is the opening stock.
         */
        return $this->createSingleUnitProduct( $row, $branchId, $authorId );
    }

    /**
     * Price and stock for a barcode the catalogue already carries.
     *
     * The unit ladder is left exactly as it is. Where the goods hold their stock on a base unit,
     * a quantity stated against a larger unit is multiplied into base units and written there,
     * because a figure on a label row would let the same goods be sold twice.
     *
     * @param array<string, mixed> $row
     */
    private function refreshExisting( ProductUnitQuantity $unitQuantity, array $row, int $authorId, string $mode ): void
    {
        $product = Product::find( $unitQuantity->product_id );

        if ( ! $product instanceof Product ) {
            throw new \RuntimeException( 'บาร์โค้ดนี้มีแถวหน่วยแต่ไม่มีสินค้า' );
        }

        $this->applyPrice( $unitQuantity, (float) $row[ 'sale' ], (float) $row[ 'cost' ] );

        $unitQuantity->save();

        $this->applyCountedQuantity(
            product: $product,
            unitQuantity: $unitQuantity,
            counted: (float) $row[ 'qty' ],
            unitPrice: (float) $row[ 'cost' ],
            authorId: $authorId,
            mode: $mode,
            note: $row[ 'note' ] ?? '',
            source: self::SOURCE_CSV
        );

        $unit = Unit::find( $unitQuantity->unit_id );
        $onBaseUnit = (bool) $product->stock_on_base_unit;

        /**
         * Publishing follows the price. A product already on sale is never taken off by an
         * upload that prices it, and one priced at nothing is never published.
         */
        if ( (float) $row[ 'sale' ] > 0 ) {
            if ( $product->status !== Product::STATUS_AVAILABLE || ! (bool) $product->searchable ) {
                $product->status = Product::STATUS_AVAILABLE;
                $product->searchable = true;
                $product->save();
            }

            return;
        }

        if ( ! $onBaseUnit || (bool) ( $unit?->base_unit ) === true ) {
            $product->status = Product::STATUS_UNAVAILABLE;
            $product->save();
        }
    }

    /**
     * A barcode the catalogue does not know becomes an ordinary one unit product, its own
     * หน่วยขาย as the unit and its own จำนวน as the stock — so a line reading "1 หีบ" is stocked
     * as one หีบ, which is what the branch counted.
     *
     * @param array<string, mixed> $row
     */
    private function createSingleUnitProduct( array $row, int $branchId, int $authorId ): string
    {
        $product = Product::where( 'barcode', $row[ 'barcode' ] )->first();

        $isNew = ! $product instanceof Product;

        if ( $isNew ) {
            $product = new Product;
            $product->barcode = $row[ 'barcode' ];
        }

        $unit = $this->resolveSingleUnit( (string) $row[ 'saleUnit' ], $authorId );

        $product->sku = $row[ 'stock' ] !== '' ? $row[ 'stock' ] : $row[ 'barcode' ];
        $product->name = mb_substr( (string) $row[ 'name' ], 0, 190 );
        $product->barcode_type = 'code128';
        $product->category_id = $this->resolveCategory( $row, $authorId );
        $product->unit_group = $unit[ 'group_id' ];
        $product->product_type = 'product';
        $product->type = Product::TYPE_MATERIALIZED;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->accurate_tracking = false;
        $product->auto_cogs = true;
        $product->searchable = true;
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->tax_type = 'inclusive';
        $product->tax_group_id = $this->vatGroupId();
        $product->stock_on_base_unit = false;
        $product->author_id = $authorId;

        $product->status = (float) $row[ 'sale' ] > 0
            ? Product::STATUS_AVAILABLE
            : Product::STATUS_UNAVAILABLE;

        $product->save();

        $unitQuantity = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $unit[ 'unit_id' ] )
            ->where( 'branch_id', $branchId )
            ->first();

        if ( ! $unitQuantity instanceof ProductUnitQuantity ) {
            $unitQuantity = new ProductUnitQuantity;
            $unitQuantity->product_id = $product->id;
            $unitQuantity->unit_id = $unit[ 'unit_id' ];
            $unitQuantity->branch_id = $branchId;
        }

        $unitQuantity->barcode = $row[ 'barcode' ];
        $unitQuantity->quantity = (float) $row[ 'qty' ];
        $this->applyPrice( $unitQuantity, (float) $row[ 'sale' ], (float) $row[ 'cost' ] );
        $unitQuantity->save();

        return $isNew ? 'created' : 'updated';
    }

    /**
     * A product described by its whole unit ladder in one row.
     *
     * The smallest level present carries the stock and the larger ones are price labels that
     * convert into it, which is the arrangement the till already obeys. A level the row left
     * blank is a unit the shop does not sell separately, so no row is made for it, and a level
     * with no price is loaded no further than the catalogue: a scannable barcode at nothing is
     * how goods leave a shop for free.
     *
     * @param  array<string, mixed> $row
     * @return string               created|updated
     */
    private function importLadderRow( array $row, int $branchId, int $authorId, string $mode ): string
    {
        /** @var list<array<string, mixed>> $levels */
        $levels = $row[ 'levels' ];

        $baseLevel = $levels[ 0 ];
        // Cost is per declared purchase/count unit; blank retains the legacy base-unit meaning.
        $costRow = $row;
        $costRow[ 'qty' ] = 1;
        $costRow[ 'receiveUnit' ] = trim( (string) ( $row[ 'costUnit' ] ?? '' ) ) ?: ( $row[ 'receiveUnit' ] ?? '' );
        $costFactor = $this->ladderQuantityInBaseUnits( $costRow, $levels )[ 'quantity' ];
        $baseCost = (float) $row[ 'cost' ] / $costFactor;
        $providerCode = trim( (string) ( $row[ 'providerCode' ] ?? '' ) );
        $providerName = trim( (string) ( $row[ 'providerName' ] ?? '' ) );
        $provider = null;
        if ( $providerCode !== '' || $providerName !== '' ) {
            $providers = Provider::query();
            $providerCode !== '' ? $providers->where( 'code', $providerCode ) : $providers->where( 'first_name', $providerName );
            $matches = $providers->limit( 2 )->get();
            if ( $matches->count() !== 1 ) {
                throw new \RuntimeException( 'ไม่พบซัพพลายเออร์ที่ตรงเพียงรายการเดียว กรุณาตรวจรหัสซัพพลายเออร์ในไฟล์' );
            }
            $provider = $matches->first();
        }

        /**
         * Only levels that can be sold get a row. The base is exempt because it decides whether
         * the goods can be sold at all, and an unpriced base leaves the product withheld.
         */
        /**
         * A level with no barcode is never sellable whatever price it carries. The divided layout
         * uses such levels to describe the sack a delivery is counted in, and giving one a row would
         * put a scannable price on a unit that has no code to scan.
         */
        $sellable = array_values( array_filter(
            $levels,
            static fn ( array $level, int $index ): bool => (string) $level[ 'barcode' ] !== ''
                && ( $index === 0 || (float) $level[ 'sale' ] > 0 ),
            ARRAY_FILTER_USE_BOTH
        ) );

        if ( $sellable === [] ) {
            throw new \RuntimeException( 'แถวนี้ไม่มีหน่วยที่ขายได้เลย' );
        }

        $unitGroupId = $this->resolveLadderUnitGroup( $sellable, $authorId );
        $unitIds = $this->resolveLadderUnits( $unitGroupId, $sellable, $authorId );

        $product = $this->findProductForLadder( $levels );

        $isNew = ! $product instanceof Product;

        if ( $isNew ) {
            $product = new Product;
            $product->barcode = $baseLevel[ 'barcode' ];
        }

        $product->sku = $row[ 'stock' ] !== '' ? $row[ 'stock' ] : $baseLevel[ 'barcode' ];
        $product->name = mb_substr( (string) $row[ 'name' ], 0, 190 );
        $product->barcode_type = 'code128';
        $product->category_id = $this->resolveCategory( $row, $authorId );
        $product->unit_group = $unitGroupId;
        $product->product_type = 'product';
        $product->type = Product::TYPE_MATERIALIZED;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->accurate_tracking = false;
        $product->auto_cogs = true;
        $product->searchable = true;
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->tax_type = 'inclusive';
        $product->tax_group_id = $this->vatGroupId();

        /**
         * More than one level means the larger ones are labels over a single stock figure, which
         * is what this flag switches on. A lone level is an ordinary one unit product.
         */
        $product->stock_on_base_unit = count( $sellable ) > 1;

        $product->status = (float) $baseLevel[ 'sale' ] > 0
            ? Product::STATUS_AVAILABLE
            : Product::STATUS_UNAVAILABLE;

        $product->author_id = $authorId;
        if ( $provider !== null ) {
            $product->provider_id = $provider->id;
        }
        $product->save();

        $baseRow = null;

        foreach ( $sellable as $index => $level ) {
            $unitId = $unitIds[ $this->ladderUnitKey( $level ) ];

            $unitQuantity = ProductUnitQuantity::where( 'product_id', $product->id )
                ->where( 'unit_id', $unitId )
                ->where( 'branch_id', $branchId )
                ->first();

            if ( ! $unitQuantity instanceof ProductUnitQuantity ) {
                $unitQuantity = new ProductUnitQuantity;
                $unitQuantity->product_id = $product->id;
                $unitQuantity->unit_id = $unitId;
                $unitQuantity->branch_id = $branchId;
                $unitQuantity->quantity = 0;
            }

            $unitQuantity->barcode = $level[ 'barcode' ];
            $this->applyPrice( $unitQuantity, (float) $level[ 'sale' ], $baseCost * (float) $level[ 'value' ] );

            /**
             * A label row never holds a figure, or the same goods could be sold twice.
             */
            if ( $index > 0 ) {
                $unitQuantity->quantity = 0;
            }

            $unitQuantity->save();

            if ( $index === 0 ) {
                $baseRow = $unitQuantity;
            }
        }

        /**
         * Units the row no longer sells separately lose their rows, unless a receipt points at
         * one, in which case it is emptied and its barcode cleared so a scan cannot reach it.
         */
        $this->clearUnlistedUnits( $product, array_values( $unitIds ), $branchId );

        if ( $baseRow instanceof ProductUnitQuantity && (float) $row[ 'qty' ] !== 0.0 ) {
            /**
             * Every declared level counts here, not only the sellable ones. A wholesaler
             * receives in cases and sells in packs all the time: the case is a unit the goods
             * arrive in, and refusing to count in it would force the storeman to multiply by
             * hand — which is the arithmetic this page exists to take off him. Carrying a price
             * is what makes a level sellable; carrying a barcode is what makes it exist.
             */
            $stated = $this->ladderQuantityInBaseUnits( $row, $levels );

            $this->applyQuantity(
                unitQuantity: $baseRow,
                stated: $stated[ 'quantity' ],
                unitPrice: $baseCost,
                authorId: $authorId,
                mode: $mode,
                note: $stated[ 'note' ]
            );
        }

        return $isNew ? 'created' : 'updated';
    }

    /**
     * Converts the received figure into base units.
     *
     * The row states how many it received and in which unit. An unnamed unit is taken as the
     * base, because that is the unit the shop counts singles in.
     *
     * @param  array<string, mixed>                 $row
     * @param  list<array<string, mixed>>           $levels
     * @return array{quantity: float, note: string}
     */
    private function ladderQuantityInBaseUnits( array $row, array $levels ): array
    {
        $stated = (float) $row[ 'qty' ];
        $wanted = trim( (string) ( $row[ 'receiveUnit' ] ?? '' ) );

        if ( $wanted === '' ) {
            return [ 'quantity' => $stated, 'note' => '' ];
        }

        foreach ( $levels as $level ) {
            if ( (string) $level[ 'unit' ] !== $wanted ) {
                continue;
            }

            $factor = (float) $level[ 'value' ];

            return [
                'quantity' => $stated * $factor,
                'note' => abs( $factor - 1.0 ) > 0.0001
                    ? sprintf( 'นับมาเป็น %s %s (×%s)', $this->trimNumber( $stated ), $wanted, $this->trimNumber( $factor ) )
                    : '',
            ];
        }

        /**
         * Divided goods can also be counted in what the sizes are measured in. A sack has no
         * barcode and often no size column of its own, but the delivery note says ten thousand
         * grams, and that is a figure the row can already convert: ten thousand grams against a
         * hundred gram bag is a hundred bags.
         */
        $countUnit = trim( (string) ( $row[ 'countUnit' ] ?? '' ) );
        $baseSize = (float) ( $row[ 'baseSize' ] ?? 0 );

        if ( $countUnit !== '' && $baseSize > 0 && $wanted === $countUnit ) {
            return [
                'quantity' => $stated / $baseSize,
                'note' => sprintf( 'นับมาเป็น %s %s', $this->trimNumber( $stated ), $countUnit ),
            ];
        }

        throw new \RuntimeException( sprintf(
            'บรรทัด %d (%s): หน่วยที่นับ "%s" ไม่ตรงกับหน่วยใดที่แถวนี้ประกาศไว้',
            $row[ 'line' ],
            $row[ 'name' ],
            $wanted
        ) );
    }

    /**
     * The product a ladder row belongs to, looked up by any of the barcodes it declares so a
     * second upload updates the goods instead of making a copy of them.
     *
     * @param list<array<string, mixed>> $levels
     */
    private function findProductForLadder( array $levels ): ?Product
    {
        foreach ( $levels as $level ) {
            $product = Product::where( 'barcode', $level[ 'barcode' ] )->first();

            if ( $product instanceof Product ) {
                return $product;
            }
        }

        foreach ( $levels as $level ) {
            $unitQuantity = ProductUnitQuantity::where( 'barcode', $level[ 'barcode' ] )->first();

            if ( $unitQuantity instanceof ProductUnitQuantity ) {
                $product = Product::find( $unitQuantity->product_id );

                if ( $product instanceof Product ) {
                    return $product;
                }
            }
        }

        return null;
    }

    /**
     * @param list<int> $keepUnitIds
     */
    private function clearUnlistedUnits( Product $product, array $keepUnitIds, int $branchId ): void
    {
        $obsolete = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'branch_id', $branchId )
            ->whereNotIn( 'unit_id', $keepUnitIds )
            ->get();

        foreach ( $obsolete as $unitQuantity ) {
            $onAReceipt = DB::table( 'nexopos_orders_products' )
                ->where( 'unit_quantity_id', $unitQuantity->id )
                ->exists();

            if ( ! $onAReceipt ) {
                $unitQuantity->delete();

                continue;
            }

            $unitQuantity->barcode = null;
            $unitQuantity->quantity = 0;
            $unitQuantity->visible = false;
            $unitQuantity->save();
        }
    }

    /**
     * @param list<array<string, mixed>> $levels
     */
    private function ladderUnitKey( array $level ): string
    {
        return $level[ 'unit' ] . '|' . $this->trimNumber( (float) $level[ 'value' ] );
    }

    /**
     * One unit group per distinct ladder shape, shared by every product with that shape, so the
     * catalogue keeps a few dozen groups rather than one per product.
     *
     * @param list<array<string, mixed>> $levels
     */
    private function resolveLadderUnitGroup( array $levels, int $authorId ): int
    {
        $signature = implode( ' / ', array_map(
            fn ( array $level ): string => $level[ 'unit' ] . ':' . $this->trimNumber( (float) $level[ 'value' ] ),
            $levels
        ) );

        $name = mb_substr( implode( ' / ', array_map(
            fn ( array $level ): string => $level[ 'unit' ] . ( (float) $level[ 'value' ] > 1 ? ' ×' . $this->trimNumber( (float) $level[ 'value' ] ) : '' ),
            $levels
        ) ), 0, 180 );

        $group = UnitGroup::where( 'name', $name )->first();

        if ( ! $group instanceof UnitGroup ) {
            $group = new UnitGroup;
            $group->name = $name;
            $group->description = 'สร้างจากไฟล์อัพโหลด อัตราแปลง ' . $signature;
            $group->author_id = $authorId;
            $group->save();
        }

        return (int) $group->id;
    }

    /**
     * @param  list<array<string, mixed>> $levels
     * @return array<string, int>         unit key => unit id
     */
    private function resolveLadderUnits( int $unitGroupId, array $levels, int $authorId ): array
    {
        $resolved = [];

        foreach ( $levels as $index => $level ) {
            $key = $this->ladderUnitKey( $level );

            $unit = Unit::where( 'group_id', $unitGroupId )
                ->where( 'name', $level[ 'unit' ] )
                ->where( 'value', $level[ 'value' ] )
                ->first();

            if ( ! $unit instanceof Unit ) {
                $unit = new Unit;
                $unit->name = $level[ 'unit' ];

                /**
                 * Thai text slugs to nothing, so the identifier is derived from the group and
                 * the ratio instead of the name.
                 */
                $unit->identifier = 'up-' . $unitGroupId . '-' . substr( md5( $key ), 0, 10 );
                $unit->group_id = $unitGroupId;
                $unit->value = $level[ 'value' ];
                $unit->base_unit = $index === 0;
                $unit->author_id = $authorId;
                $unit->save();
            }

            $resolved[ $key ] = (int) $unit->id;
        }

        return $resolved;
    }

    /**
     * Moves a stock figure and leaves a trace of the move.
     *
     * Receiving adds to what is on hand. Setting writes the stated figure and records the
     * difference, so a count that finds fewer goods reads as a removal rather than as a receipt.
     *
     * The row is re-read inside a lock before the arithmetic, because a till can be selling the
     * same goods while the file is being loaded: without it, a sale landing between the read and
     * the write would be paid for by the shop twice over — once deducted by the till, then added
     * back by a figure computed from a stale number.
     */
    /**
     * Writes a counted figure against the unit it was counted in, converting it first where the goods
     * hold their stock somewhere else.
     *
     * Where `stock_on_base_unit` is set, stock lives on the base unit alone and a figure counted in a
     * larger unit has to be multiplied into base units before it is written — two cases of twenty four
     * are forty eight pieces. Writing it onto the larger unit's own row instead would let the same goods
     * be sold twice, once as cases and once as pieces.
     *
     * Lifted out of refreshExisting() unchanged so that the barcode entry path applies the identical
     * rule. A scanned case barcode and a case counted in a spreadsheet are the same event, and the two
     * must not be able to drift apart.
     */
    private function applyCountedQuantity(
        Product $product,
        ProductUnitQuantity $unitQuantity,
        float $counted,
        float $unitPrice,
        int $authorId,
        string $mode,
        string $note = '',
        string $source = ''
    ): void {
        $unit = Unit::find( $unitQuantity->unit_id );
        $onBaseUnit = (bool) $product->stock_on_base_unit;

        if ( ! $onBaseUnit || ! $unit instanceof Unit || (bool) $unit->base_unit === true ) {
            $this->applyQuantity(
                unitQuantity: $unitQuantity,
                stated: $counted,
                unitPrice: $unitPrice,
                authorId: $authorId,
                mode: $mode,
                note: $note,
                source: $source
            );

            return;
        }

        $baseUnit = Unit::where( 'group_id', $unit->group_id )->where( 'base_unit', true )->first();

        $baseRow = $baseUnit instanceof Unit
            ? ProductUnitQuantity::where( 'product_id', $product->id )
                ->where( 'unit_id', $baseUnit->id )
                ->where( 'branch_id', $unitQuantity->branch_id )
                ->first()
            : null;

        if ( ! $baseRow instanceof ProductUnitQuantity || (float) $baseUnit->value <= 0 ) {
            return;
        }

        $inBaseUnits = $counted * ( (float) $unit->value / (float) $baseUnit->value );

        $this->applyQuantity(
            unitQuantity: $baseRow,
            stated: $inBaseUnits,
            unitPrice: $unitPrice,
            authorId: $authorId,
            mode: $mode,
            note: sprintf( 'นับมาเป็น %s %s', $this->trimNumber( $counted ), (string) $unit->name ),
            source: $source
        );
    }

    /**
     * How the goods came to be counted, for the line the history shows.
     *
     * Reading "รับสินค้าเข้าจากไฟล์ CSV" against a figure somebody keyed in by scanning the shelf sends
     * whoever is checking the movement looking for a file that never existed.
     */
    private const SOURCE_CSV = 'ไฟล์ CSV';

    private const SOURCE_SCAN = 'การยิงบาร์โค้ด';

    private function applyQuantity(
        ProductUnitQuantity $unitQuantity,
        float $stated,
        float $unitPrice,
        int $authorId,
        string $mode,
        string $note = '',
        string $source = ''
    ): void {
        $fresh = ProductUnitQuantity::where( 'id', $unitQuantity->id )->lockForUpdate()->first();

        if ( ! $fresh instanceof ProductUnitQuantity ) {
            throw new \RuntimeException( 'แถวหน่วยหายไประหว่างนำเข้า' );
        }

        $before = (float) $fresh->quantity;

        $after = $mode === self::MODE_RECEIVE
            ? $before + $stated
            : $stated;

        $fresh->quantity = $after;
        $fresh->save();

        $movement = $after - $before;

        if ( abs( $movement ) < 0.00001 ) {
            return;
        }

        $description = $mode === self::MODE_RECEIVE
            ? 'รับสินค้าเข้าจาก' . ( $source !== '' ? $source : self::SOURCE_CSV )
            : 'ตั้งยอดคงเหลือใหม่จาก' . ( $source !== '' ? $source : self::SOURCE_CSV );

        if ( $note !== '' ) {
            $description .= ' — ' . $note;
        }

        $history = new ProductHistory;
        $history->product_id = $fresh->product_id;
        $history->unit_id = $fresh->unit_id;
        $history->branch_id = $fresh->branch_id;
        $history->operation_type = $movement > 0
            ? ProductHistory::ACTION_ADDED
            : ProductHistory::ACTION_REMOVED;
        $history->before_quantity = $before;
        $history->quantity = abs( $movement );
        $history->after_quantity = $after;
        $history->unit_price = $unitPrice;
        $history->total_price = round( $unitPrice * abs( $movement ), 5 );
        $history->description = $description;
        $history->author_id = $authorId;
        $history->save();
    }

    private function trimNumber( float $value ): string
    {
        $formatted = number_format( $value, 2, '.', ',' );

        return str_contains( $formatted, '.' ) ? rtrim( rtrim( $formatted, '0' ), '.' ) : $formatted;
    }

    /**
     * The shop quotes every price with VAT already inside, so the net is taken out of the price
     * rather than added on top. Writing sale_price alone left the receipt and the VAT return
     * disagreeing with what the till charged.
     */
    private function applyPrice( ProductUnitQuantity $unitQuantity, float $price, float $cost ): void
    {
        $net = round( $price / ( 1 + ( self::VAT_RATE / 100 ) ), 5 );
        $tax = round( $price - $net, 5 );

        $unitQuantity->type = 'product';
        $unitQuantity->visible = true;
        $unitQuantity->is_weighable = false;
        $unitQuantity->low_quantity = 0;
        $unitQuantity->stock_alert_enabled = false;
        $unitQuantity->sale_price_edit = $price;
        $unitQuantity->sale_price = $price;
        $unitQuantity->sale_price_gross = $price;
        $unitQuantity->sale_price_net = $net;
        $unitQuantity->sale_price_tax = $tax;
        $unitQuantity->wholesale_price_edit = $price;
        $unitQuantity->wholesale_price = $net;
        $unitQuantity->wholesale_price_gross = $price;
        $unitQuantity->wholesale_price_net = $net;
        $unitQuantity->wholesale_price_tax = $tax;
        $unitQuantity->cogs = $cost;
    }

    /**
     * @return array{unit_id: int, group_id: int}
     */
    private function resolveSingleUnit( string $name, int $authorId ): array
    {
        $name = trim( $name ) !== '' ? trim( $name ) : 'ชิ้น';

        $unit = Unit::where( 'name', $name )
            ->where( 'value', 1 )
            ->where( 'base_unit', true )
            ->whereHas( 'group', fn ( $query ) => $query->where( 'name', $name ) )
            ->first();

        if ( $unit instanceof Unit ) {
            return [ 'unit_id' => (int) $unit->id, 'group_id' => (int) $unit->group_id ];
        }

        $group = UnitGroup::firstOrCreate( [ 'name' => $name ], [ 'author_id' => $authorId ] );

        $unit = Unit::where( 'group_id', $group->id )->where( 'name', $name )->first();

        if ( ! $unit instanceof Unit ) {
            $unit = new Unit;
            $unit->name = $name;
            $unit->identifier = 'su-' . $group->id . '-' . substr( md5( $name ), 0, 10 );
            $unit->group_id = $group->id;
            $unit->value = 1;
            $unit->base_unit = true;
            $unit->author_id = $authorId;
            $unit->save();
        }

        return [ 'unit_id' => (int) $unit->id, 'group_id' => (int) $group->id ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveCategory( array $row, int $authorId ): int
    {
        $main = trim( (string) $row[ 'category' ] );
        $sub = trim( (string) $row[ 'subCategory' ] );

        $name = $main === ''
            ? 'ไม่ระบุหมวด'
            : 'กลุ่ม ' . $main . ( $sub !== '' ? '-' . $sub : '' );

        return (int) ProductCategory::firstOrCreate(
            [ 'name' => $name ],
            [
                'description' => '',
                'author_id' => $authorId,
                'parent_id' => 0,
                'displays_on_pos' => true,
            ]
        )->id;
    }

    /**
     * A product pointed at a group with no rate inside it reports zero VAT, which is how a
     * till comes to print 0.00 on an inclusive catalogue.
     */
    private function ensureVatGroup( int $authorId ): void
    {
        $group = TaxGroup::where( 'name', self::VAT_GROUP_NAME )->first();

        if ( ! $group instanceof TaxGroup ) {
            $group = new TaxGroup;
            $group->name = self::VAT_GROUP_NAME;
            $group->description = 'ภาษีมูลค่าเพิ่มตามประมวลรัษฎากร อัตราปัจจุบันร้อยละ 7';
            $group->author_id = $authorId;
            $group->save();
        }

        if ( ! Tax::where( 'tax_group_id', $group->id )->exists() ) {
            $tax = new Tax;
            $tax->name = self::VAT_GROUP_NAME;
            $tax->description = 'อัตราร้อยละ 7';
            $tax->rate = self::VAT_RATE;
            $tax->tax_group_id = $group->id;
            $tax->author_id = $authorId;
            $tax->save();
        }
    }

    private function vatGroupId(): ?int
    {
        return TaxGroup::where( 'name', self::VAT_GROUP_NAME )->value( 'id' );
    }
}
