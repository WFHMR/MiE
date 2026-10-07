<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\InventoryImportLock;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Receiving and counting goods by scanning the shelf.
 *
 * The stock page could only be fed a spreadsheet. Receiving a delivery of eight lines meant opening
 * Excel, finding each barcode, typing the figure and uploading the file — so in practice the count
 * happened on paper and reached the system late or not at all.
 *
 * What is pinned here is everything that makes a scanned figure land in the same place a spreadsheet
 * figure would: the two modes, the base-unit conversion, the branch the row belongs to, the fact that
 * goods not yet priced can still be received, and the import lock the file upload shares.
 *
 * Rows are built and torn down by hand rather than through DatabaseTransactions, because the endpoint
 * opens a transaction per line exactly as the file import does, and a surrounding transaction hides
 * whether the per-line ones commit.
 */
class InventoryScanEntryTest extends TestCase
{
    use WithAuthentication;

    /**
     * @var array<int, int>
     */
    private array $productIds = [];

    /**
     * @var array<int, int>
     */
    private array $groupIds = [];

    private ?int $branchId = null;

    private ?int $otherBranchId = null;

    protected function tearDown(): void
    {
        ProductHistory::whereIn( 'product_id', $this->productIds )->delete();
        ProductUnitQuantity::whereIn( 'product_id', $this->productIds )->delete();
        Product::whereIn( 'id', $this->productIds )->delete();
        Unit::whereIn( 'group_id', $this->groupIds )->delete();
        UnitGroup::whereIn( 'id', $this->groupIds )->delete();

        app( InventoryImportLock::class )->forceRelease();

        parent::tearDown();
    }

    private function givenBranch( string $name ): Branch
    {
        $branch = Branch::where( 'name', $name )->first();

        if ( $branch instanceof Branch ) {
            return $branch;
        }

        $branch = new Branch;
        $branch->name = $name;
        $branch->code = strtoupper( substr( md5( $name ), 0, 6 ) );
        $branch->is_active = true;
        $branch->author_id = 1;
        $branch->save();

        return $branch;
    }

    private function branch(): int
    {
        return $this->branchId ??= $this->givenBranch( 'สาขาทดสอบยิงบาร์โค้ด' )->id;
    }

    private function otherBranch(): int
    {
        return $this->otherBranchId ??= $this->givenBranch( 'สาขาอื่นทดสอบยิงบาร์โค้ด' )->id;
    }

    /**
     * Goods sold loose and by the pack, with stock held on whichever unit the caller asks for.
     *
     * `stock_on_base_unit` is the switch that decides whether a pack scanned at the door moves the pack
     * row or the piece row, and it is the rule most likely to be got wrong, so both shapes are built.
     *
     * @return array{product: Product, piece: Unit, pack: Unit}
     */
    private function givenProduct( bool $stockOnBaseUnit, int $piecesPerPack = 12, bool $onSale = true ): array
    {
        $group = new UnitGroup;
        $group->name = 'กลุ่มหน่วยยิงทดสอบ ' . random_int( 1000, 9999 );
        $group->author_id = 1;
        $group->save();

        $this->groupIds[] = $group->id;

        $piece = new Unit;
        $piece->name = 'ชิ้น';
        $piece->identifier = 'scan-piece-' . random_int( 1000, 9999 );
        $piece->value = 1;
        $piece->base_unit = true;
        $piece->group_id = $group->id;
        $piece->author_id = 1;
        $piece->save();

        $pack = new Unit;
        $pack->name = 'แพ็ค';
        $pack->identifier = 'scan-pack-' . random_int( 1000, 9999 );
        $pack->value = $piecesPerPack;
        $pack->base_unit = false;
        $pack->group_id = $group->id;
        $pack->author_id = 1;
        $pack->save();

        $product = new Product;
        $product->name = 'สินค้าทดสอบยิงบาร์โค้ด ' . random_int( 1000, 9999 );
        $product->sku = 'SCAN-' . random_int( 100000, 999999 );
        $product->barcode = (string) random_int( 8800000000000, 8899999999999 );
        $product->barcode_type = 'code128';
        $product->unit_group = $group->id;
        $product->product_type = 'product';
        $product->type = Product::TYPE_MATERIALIZED;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->accurate_tracking = false;
        $product->auto_cogs = true;
        $product->searchable = $onSale;
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->status = $onSale ? Product::STATUS_AVAILABLE : Product::STATUS_UNAVAILABLE;
        $product->stock_on_base_unit = $stockOnBaseUnit;
        $product->author_id = 1;
        $product->save();

        $this->productIds[] = $product->id;

        /**
         * The piece row carries the product's own barcode, which is how this catalogue is built, and the
         * pack row carries its own. That is what lets a scan tell the two apart.
         */
        $this->givenUnitRow( $product, $piece, $this->branch(), (string) $product->barcode, 0 );
        $this->givenUnitRow( $product, $pack, $this->branch(), $product->barcode . '-PK', 0 );

        return [ 'product' => $product, 'piece' => $piece, 'pack' => $pack ];
    }

    private function givenUnitRow( Product $product, Unit $unit, int $branchId, string $barcode, float $quantity ): ProductUnitQuantity
    {
        $row = new ProductUnitQuantity;
        $row->product_id = $product->id;
        $row->unit_id = $unit->id;
        $row->branch_id = $branchId;
        $row->barcode = $barcode;
        $row->type = 'product';
        $row->visible = true;
        $row->is_weighable = false;
        $row->low_quantity = 0;
        $row->stock_alert_enabled = false;
        $row->quantity = $quantity;

        foreach ( [ 'sale_price', 'sale_price_edit', 'sale_price_gross', 'sale_price_net', 'wholesale_price', 'wholesale_price_edit', 'wholesale_price_gross', 'wholesale_price_net' ] as $field ) {
            $row->$field = 20;
        }

        $row->sale_price_tax = 0;
        $row->wholesale_price_tax = 0;
        $row->cogs = 8;
        $row->save();

        return $row;
    }

    private function rowFor( Product $product, Unit $unit, ?int $branchId = null ): ProductUnitQuantity
    {
        return ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $unit->id )
            ->where( 'branch_id', $branchId ?? $this->branch() )
            ->firstOrFail();
    }

    private function lookup( string $barcode, ?int $branchId = null )
    {
        return $this->withSession( $this->app[ 'session' ]->all() )->json(
            'GET',
            '/api/inventory/scan/lookup/' . rawurlencode( $barcode ) . '?' . http_build_query( [
                'branch_id' => $branchId ?? $this->branch(),
            ] )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function apply( array $lines, string $mode, ?int $branchId = null )
    {
        return $this->withSession( $this->app[ 'session' ]->all() )->json( 'POST', '/api/inventory/scan/apply', [
            'branch_id' => $branchId ?? $this->branch(),
            'stock_mode' => $mode,
            'lines' => $lines,
        ] );
    }

    public function test_a_scanned_barcode_answers_with_the_unit_it_belongs_to(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'pack' => $pack ] = $this->givenProduct( stockOnBaseUnit: false );

        $response = $this->lookup( $product->barcode . '-PK' );

        $response->assertOk();
        $response->assertJsonPath( 'status', 'success' );
        $response->assertJsonPath( 'line.unit_id', $pack->id );
        $response->assertJsonPath( 'line.unit_name', 'แพ็ค' );
        $response->assertJsonPath( 'line.product_id', $product->id );
        $this->assertSame(
            $this->rowFor( $product, $pack )->id,
            $response->json( 'line.unit_quantity_id' ),
            'The scan answered with a different row than the one carrying the barcode.'
        );
    }

    /**
     * A barcode nothing answers to has to say so plainly. Reporting it as a server fault sends the desk
     * looking for a broken reader instead of a product that was never created.
     */
    public function test_an_unknown_barcode_is_reported_as_unknown(): void
    {
        $this->attemptAuthenticate();

        $this->lookup( '8999999999999' )
            ->assertStatus( 404 )
            ->assertJsonPath( 'status', 'error' );
    }

    /**
     * Goods are received before they are priced, and the importer withholds a product priced at nothing
     * so a scan at the till cannot give it away. Receiving a delivery of exactly those goods is the
     * reason this page exists, so the sale-side restriction must not apply here.
     */
    public function test_goods_not_yet_on_sale_can_still_be_received(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'piece' => $piece ] = $this->givenProduct( stockOnBaseUnit: false, onSale: false );

        $this->lookup( (string) $product->barcode )
            ->assertOk()
            ->assertJsonPath( 'status', 'success' )
            ->assertJsonPath( 'line.unit_id', $piece->id );

        $this->apply( [ [ 'unit_quantity_id' => $this->rowFor( $product, $piece )->id, 'quantity' => 5 ] ], 'receive' )
            ->assertOk();

        $this->assertSame( 5.0, (float) $this->rowFor( $product, $piece )->quantity );
    }

    public function test_receiving_adds_to_what_is_already_on_the_shelf(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'piece' => $piece ] = $this->givenProduct( stockOnBaseUnit: false );

        $row = $this->rowFor( $product, $piece );
        $row->quantity = 68;
        $row->save();

        $this->apply( [ [ 'unit_quantity_id' => $row->id, 'quantity' => 37 ] ], 'receive' )
            ->assertOk()
            ->assertJsonPath( 'applied', 1 );

        $this->assertSame( 105.0, (float) $this->rowFor( $product, $piece )->quantity, 'Receiving did not add to the figure already on hand.' );
    }

    public function test_counting_replaces_what_the_system_believed(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'piece' => $piece ] = $this->givenProduct( stockOnBaseUnit: false );

        $row = $this->rowFor( $product, $piece );
        $row->quantity = 68;
        $row->save();

        $this->apply( [ [ 'unit_quantity_id' => $row->id, 'quantity' => 37 ] ], 'set' )
            ->assertOk();

        $this->assertSame( 37.0, (float) $this->rowFor( $product, $piece )->quantity, 'A count did not replace the figure.' );
    }

    /**
     * The rule that decides whether the shop can sell the same goods twice.
     *
     * Where stock lives on the base unit, a pack scanned at the door is twelve pieces on the piece row,
     * not one on the pack row. Writing it onto the pack row would leave the goods sellable as a pack and
     * as twelve pieces at the same time.
     */
    public function test_a_pack_scanned_on_base_unit_goods_lands_on_the_piece_row(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'piece' => $piece, 'pack' => $pack ] = $this->givenProduct( stockOnBaseUnit: true, piecesPerPack: 12 );

        $lookup = $this->lookup( $product->barcode . '-PK' );
        $lookup->assertOk();
        $lookup->assertJsonPath( 'line.stocks_on_unit_name', 'ชิ้น' );
        $this->assertSame( 12.0, (float) $lookup->json( 'line.stocks_on_factor' ), 'The screen was not told how many pieces a pack becomes.' );

        $this->apply( [ [ 'unit_quantity_id' => $this->rowFor( $product, $pack )->id, 'quantity' => 2 ] ], 'receive' )
            ->assertOk();

        $this->assertSame( 24.0, (float) $this->rowFor( $product, $piece )->quantity, 'Two packs of twelve did not become twenty four pieces.' );
        $this->assertSame( 0.0, (float) $this->rowFor( $product, $pack )->quantity, 'The pack row was written to as well, so the goods can be sold twice.' );
    }

    /**
     * Where stock is not held on the base unit, the unit scanned is the unit written, untouched.
     */
    public function test_a_pack_scanned_on_per_unit_goods_stays_on_the_pack_row(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'piece' => $piece, 'pack' => $pack ] = $this->givenProduct( stockOnBaseUnit: false, piecesPerPack: 12 );

        $this->apply( [ [ 'unit_quantity_id' => $this->rowFor( $product, $pack )->id, 'quantity' => 2 ] ], 'receive' )
            ->assertOk();

        $this->assertSame( 2.0, (float) $this->rowFor( $product, $pack )->quantity );
        $this->assertSame( 0.0, (float) $this->rowFor( $product, $piece )->quantity );
    }

    /**
     * The row id travels through the browser. A row belonging to another branch must not become writable
     * by changing a number in the request.
     */
    public function test_a_row_from_another_branch_is_refused(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'piece' => $piece ] = $this->givenProduct( stockOnBaseUnit: false );

        $foreign = $this->givenUnitRow( $product, $piece, $this->otherBranch(), $product->barcode . '-OTHER', 40 );

        $this->apply( [ [ 'unit_quantity_id' => $foreign->id, 'quantity' => 9 ] ], 'set' )
            ->assertStatus( 422 )
            ->assertJsonPath( 'applied', 0 );

        $this->assertSame( 40.0, (float) $foreign->fresh()->quantity, 'Another branch\'s shelf was written to.' );
    }

    /**
     * A scan against a branch that does not stock the goods has to say so, not answer with another
     * branch's row.
     */
    public function test_a_barcode_not_stocked_in_the_chosen_branch_is_refused(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product ] = $this->givenProduct( stockOnBaseUnit: false );

        $this->lookup( (string) $product->barcode, $this->otherBranch() )
            ->assertStatus( 404 )
            ->assertJsonPath( 'status', 'error' );
    }

    /**
     * Every movement leaves a line, the same way a file import does, so a figure can be traced back to
     * the moment it was keyed in. The wording has to name the barcode reader rather than a CSV file that
     * never existed.
     */
    public function test_a_scanned_movement_is_recorded_in_the_history(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'piece' => $piece ] = $this->givenProduct( stockOnBaseUnit: false );

        $this->apply( [ [ 'unit_quantity_id' => $this->rowFor( $product, $piece )->id, 'quantity' => 6 ] ], 'receive' )
            ->assertOk();

        $history = ProductHistory::where( 'product_id', $product->id )->latest( 'id' )->first();

        $this->assertNotNull( $history, 'A scanned movement left no trace in the history.' );
        $this->assertSame( ProductHistory::ACTION_ADDED, $history->operation_type );
        $this->assertSame( 6.0, (float) $history->quantity );
        $this->assertSame( $this->branch(), (int) $history->branch_id );
        $this->assertStringContainsString( 'การยิงบาร์โค้ด', (string) $history->description, 'The history blames a CSV file for a figure that was scanned.' );
    }

    /**
     * A line the operator scanned and then emptied must not be written. In a count it would zero the
     * shelf, which is the most expensive way for an empty box to be interpreted.
     */
    public function test_a_line_with_no_quantity_is_refused(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'piece' => $piece ] = $this->givenProduct( stockOnBaseUnit: false );

        $row = $this->rowFor( $product, $piece );
        $row->quantity = 50;
        $row->save();

        $this->apply( [ [ 'unit_quantity_id' => $row->id, 'quantity' => 0 ] ], 'set' )
            ->assertStatus( 422 );

        $this->assertSame( 50.0, (float) $this->rowFor( $product, $piece )->quantity, 'An empty quantity emptied the shelf.' );
    }

    /**
     * Neither mode can be guessed from what was scanned, so the request is refused without one — the same
     * rule the file upload follows.
     */
    public function test_the_mode_is_required(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'piece' => $piece ] = $this->givenProduct( stockOnBaseUnit: false );

        $this->apply( [ [ 'unit_quantity_id' => $this->rowFor( $product, $piece )->id, 'quantity' => 3 ] ], '' )
            ->assertStatus( 422 );
    }

    /**
     * A count keyed in by scanning writes the same rows a file would, so the two must not run together.
     * The lock is the one the upload page and the console import commands already share.
     */
    public function test_a_scan_is_refused_while_a_file_import_is_running(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'piece' => $piece ] = $this->givenProduct( stockOnBaseUnit: false );

        $lock = app( InventoryImportLock::class )->attempt();
        $this->assertNotNull( $lock, 'The lock was already held before the test started.' );

        try {
            $this->apply( [ [ 'unit_quantity_id' => $this->rowFor( $product, $piece )->id, 'quantity' => 4 ] ], 'receive' )
                ->assertStatus( 423 );

            $this->assertSame( 0.0, (float) $this->rowFor( $product, $piece )->quantity, 'The scan wrote stock while an import was running.' );
        } finally {
            $lock->release();
        }
    }

    /**
     * Scanning is a write against the catalogue, so it takes the same permission the upload page takes.
     */
    public function test_scanning_requires_the_product_creation_permission(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product ] = $this->givenProduct( stockOnBaseUnit: false );

        $routes = collect( app( 'router' )->getRoutes()->getRoutes() )
            ->filter( fn ( $route ) => str_starts_with( $route->uri(), 'api/inventory/scan' ) );

        $this->assertCount( 2, $routes, 'The scan endpoints are no longer both registered.' );

        foreach ( $routes as $route ) {
            $this->assertTrue(
                collect( $route->gatherMiddleware() )->contains( fn ( $middleware ) => is_string( $middleware ) && str_contains( $middleware, 'nexopos.create.products' ) ),
                sprintf( 'The route %s is not guarded by nexopos.create.products.', $route->uri() )
            );
        }
    }

    /**
     * The page has to actually mount the scanner, and the component has to be registered, or the tag is
     * rendered as unknown markup and silently does nothing — the failure mode the branch picker had.
     */
    public function test_the_upload_page_mounts_the_scanner(): void
    {
        $this->actingAs( $this->attemptGetAnyUserFromRole( 'admin' ), 'web' )
            ->get( '/dashboard/inventory/upload' )
            ->assertOk()
            ->assertSee( '<ns-inventory-scan', false );

        $this->assertStringContainsString(
            'nsInventoryScan',
            file_get_contents( base_path( 'resources/ts/app-init.ts' ) ),
            'The scanner component is not registered, so the tag would render as unknown markup.'
        );
    }
}
