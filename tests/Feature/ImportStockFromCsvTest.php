<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The counted-sheet importer, which had no test and needed one most.
 *
 * It set the figure with a single statement scoped to the product and nothing else, so on goods sold
 * through a unit ladder the same number landed on every rung: three hundred pieces, three hundred
 * packs and three hundred cases, one shelf counted three times and sellable three times. On a shop
 * with two branches it wrote both branches from one sheet.
 *
 * These tests describe where a counted figure is allowed to land, and where it must refuse to guess.
 */
class ImportStockFromCsvTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private string $path = '';

    protected function tearDown(): void
    {
        if ( $this->path !== '' && is_file( $this->path ) ) {
            unlink( $this->path );
        }

        parent::tearDown();
    }

    /**
     * @param list<string> $dataLines
     */
    private function writeSheet( array $dataLines, string $header = 'รหัสสต็อก,ชื่อสินค้า,คงเหลือ', int $titleLines = 1 ): string
    {
        $lines = [];

        for ( $n = 1; $n <= $titleLines; $n++ ) {
            $lines[] = 'รายงานนับสต๊อก บรรทัดหัวเรื่องที่ ' . $n;
        }

        $lines[] = $header;

        $this->path = tempnam( sys_get_temp_dir(), 'stk' ) . '.csv';

        file_put_contents( $this->path, implode( "\n", array_merge( $lines, $dataLines ) ) . "\n" );

        return $this->path;
    }

    private function branch( string $suffix ): Branch
    {
        $branch = new Branch;
        $branch->name = 'สาขาทดสอบนับสต๊อก ' . $suffix;
        $branch->code = 'STK' . $suffix . random_int( 10, 99 );
        $branch->is_main = false;
        $branch->author_id = 1;
        $branch->save();

        return $branch;
    }

    /**
     * @param  array<string, float>                                $units unit name to its value
     * @return array{product: Product, units: array<string, Unit>}
     */
    private function makeProduct( string $sku, array $units, bool $onBaseUnit ): array
    {
        $group = new UnitGroup;
        $group->name = 'กลุ่มทดสอบ ' . $sku . ' ' . random_int( 1000, 9999 );
        $group->author_id = 1;
        $group->save();

        $smallest = min( $units );
        $resolved = [];

        foreach ( $units as $name => $value ) {
            $unit = new Unit;
            $unit->name = $name;
            $unit->identifier = 'stk-' . $group->id . '-' . substr( md5( $name ), 0, 10 );
            $unit->group_id = $group->id;
            $unit->value = $value;
            $unit->base_unit = abs( $value - $smallest ) < 0.00001;
            $unit->author_id = 1;
            $unit->save();

            $resolved[ $name ] = $unit;
        }

        $product = new Product;
        $product->name = 'สินค้าทดสอบ ' . $sku;
        $product->sku = $sku;
        $product->barcode = (string) random_int( 8700000000000, 8799999999999 );
        $product->barcode_type = 'code128';
        $product->unit_group = $group->id;
        $product->product_type = 'product';
        $product->type = Product::TYPE_MATERIALIZED;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->accurate_tracking = false;
        $product->auto_cogs = true;
        $product->searchable = true;
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->status = Product::STATUS_AVAILABLE;
        $product->stock_on_base_unit = $onBaseUnit;
        $product->author_id = 1;
        $product->save();

        return [ 'product' => $product, 'units' => $resolved ];
    }

    private function stockRow( Product $product, Unit $unit, ?int $branchId, float $quantity ): ProductUnitQuantity
    {
        $row = new ProductUnitQuantity;
        $row->product_id = $product->id;
        $row->unit_id = $unit->id;
        $row->branch_id = $branchId;
        $row->type = 'product';
        $row->visible = true;
        $row->is_weighable = false;
        $row->low_quantity = 0;
        $row->stock_alert_enabled = false;
        $row->quantity = $quantity;
        $row->sale_price = 10;
        $row->sale_price_edit = 10;
        $row->sale_price_gross = 10;
        $row->sale_price_net = 10;
        $row->sale_price_tax = 0;
        $row->wholesale_price = 10;
        $row->wholesale_price_edit = 10;
        $row->wholesale_price_gross = 10;
        $row->wholesale_price_net = 10;
        $row->wholesale_price_tax = 0;
        $row->cogs = 5;
        $row->save();

        return $row;
    }

    private function quantityOf( int $rowId ): float
    {
        return (float) ProductUnitQuantity::where( 'id', $rowId )->value( 'quantity' );
    }

    /**
     * The fault this command shipped with. A counted figure belongs on the unit the shelf is counted
     * in, and nowhere else.
     */
    public function test_a_counted_figure_lands_only_on_the_unit_that_bears_the_stock(): void
    {
        $this->attemptAuthenticate();

        $branch = $this->branch( 'A' );

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            sku: 'LADDER-1',
            units: [ 'ชิ้น' => 1.0, 'แพ็ค' => 12.0, 'หีบ' => 48.0 ],
            onBaseUnit: true
        );

        $base = $this->stockRow( $product, $units[ 'ชิ้น' ], $branch->id, 120.0 );
        $pack = $this->stockRow( $product, $units[ 'แพ็ค' ], $branch->id, 0.0 );
        $case = $this->stockRow( $product, $units[ 'หีบ' ], $branch->id, 0.0 );

        $this->artisan( 'stock:import-csv', [
            'file' => $this->writeSheet( [ 'LADDER-1,สินค้าชั้นหน่วย,300' ] ),
            '--branch' => $branch->id,
        ] )->assertSuccessful();

        $this->assertSame( 300.0, $this->quantityOf( $base->id ), 'The counted figure belongs on the piece row.' );
        $this->assertSame( 0.0, $this->quantityOf( $pack->id ), 'The pack row is a label and must stay empty.' );
        $this->assertSame( 0.0, $this->quantityOf( $case->id ), 'The case row is a label and must stay empty.' );
    }

    /**
     * An ordinary product with one unit has only one row the figure can mean.
     */
    public function test_a_single_unit_product_is_set(): void
    {
        $this->attemptAuthenticate();

        $branch = $this->branch( 'B' );

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            sku: 'SINGLE-1',
            units: [ 'ชิ้น' => 1.0 ],
            onBaseUnit: false
        );

        $row = $this->stockRow( $product, $units[ 'ชิ้น' ], $branch->id, 8.0 );

        $this->artisan( 'stock:import-csv', [
            'file' => $this->writeSheet( [ 'SINGLE-1,สินค้าหน่วยเดียว,41' ] ),
            '--branch' => $branch->id,
        ] )->assertSuccessful();

        $this->assertSame( 41.0, $this->quantityOf( $row->id ) );
    }

    /**
     * A sheet counted at one branch says nothing about the other one's shelf.
     */
    public function test_only_the_named_branch_is_touched(): void
    {
        $this->attemptAuthenticate();

        $here = $this->branch( 'C' );
        $there = $this->branch( 'D' );

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            sku: 'TWOBR-1',
            units: [ 'ชิ้น' => 1.0 ],
            onBaseUnit: false
        );

        $mine = $this->stockRow( $product, $units[ 'ชิ้น' ], $here->id, 10.0 );
        $theirs = $this->stockRow( $product, $units[ 'ชิ้น' ], $there->id, 77.0 );

        $this->artisan( 'stock:import-csv', [
            'file' => $this->writeSheet( [ 'TWOBR-1,สินค้าสองสาขา,25' ] ),
            '--branch' => $here->id,
        ] )->assertSuccessful();

        $this->assertSame( 25.0, $this->quantityOf( $mine->id ) );
        $this->assertSame( 77.0, $this->quantityOf( $theirs->id ), "The other branch's shelf was rewritten from this sheet." );
    }

    /**
     * Without a branch there is no way to know whose count this is, and guessing would write the
     * wrong shelf. It is refused before anything is read.
     */
    public function test_a_sheet_with_no_branch_is_refused_once_stock_is_split_by_branch(): void
    {
        $this->attemptAuthenticate();

        $branch = $this->branch( 'E' );

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            sku: 'NOBR-1',
            units: [ 'ชิ้น' => 1.0 ],
            onBaseUnit: false
        );

        $row = $this->stockRow( $product, $units[ 'ชิ้น' ], $branch->id, 12.0 );

        $this->artisan( 'stock:import-csv', [
            'file' => $this->writeSheet( [ 'NOBR-1,สินค้าไม่บอกสาขา,99' ] ),
        ] )->assertFailed();

        $this->assertSame( 12.0, $this->quantityOf( $row->id ), 'A refused run must write nothing.' );
    }

    /**
     * Several units and nothing saying which bears the stock is not a decision this command may
     * make, so the goods are left exactly as they were and reported.
     */
    public function test_goods_with_no_declared_stock_bearing_unit_are_skipped(): void
    {
        $this->attemptAuthenticate();

        $branch = $this->branch( 'F' );

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            sku: 'AMBIG-1',
            units: [ 'ชิ้น' => 1.0, 'แพ็ค' => 6.0 ],
            onBaseUnit: false
        );

        $piece = $this->stockRow( $product, $units[ 'ชิ้น' ], $branch->id, 30.0 );
        $pack = $this->stockRow( $product, $units[ 'แพ็ค' ], $branch->id, 5.0 );

        $this->artisan( 'stock:import-csv', [
            'file' => $this->writeSheet( [ 'AMBIG-1,สินค้ากำกวม,90' ] ),
            '--branch' => $branch->id,
        ] )->assertSuccessful();

        $this->assertSame( 30.0, $this->quantityOf( $piece->id ), 'Ambiguous goods must be left alone.' );
        $this->assertSame( 5.0, $this->quantityOf( $pack->id ) );
    }

    /**
     * The header is found by reading it. The old version threw away three rows whatever they were,
     * so a file with two title lines lost its first product and one with four read a title as one.
     */
    public function test_the_header_is_found_however_many_title_lines_sit_above_it(): void
    {
        $this->attemptAuthenticate();

        foreach ( [ 0, 1, 5 ] as $titleLines ) {
            $branch = $this->branch( 'H' . $titleLines );

            $sku = 'TITLE-' . $titleLines;

            [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
                sku: $sku,
                units: [ 'ชิ้น' => 1.0 ],
                onBaseUnit: false
            );

            $row = $this->stockRow( $product, $units[ 'ชิ้น' ], $branch->id, 1.0 );

            $this->artisan( 'stock:import-csv', [
                'file' => $this->writeSheet( [ $sku . ',สินค้าทดสอบหัวเรื่อง,64' ], 'รหัสสต็อก,ชื่อสินค้า,คงเหลือ', $titleLines ),
                '--branch' => $branch->id,
            ] )->assertSuccessful();

            $this->assertSame(
                64.0,
                $this->quantityOf( $row->id ),
                sprintf( 'A sheet with %d title lines was misread.', $titleLines )
            );
        }
    }

    /**
     * A file whose columns cannot be placed is refused rather than read at fixed offsets, because
     * reading the wrong column writes a price or a cost as a stock figure.
     */
    public function test_a_file_with_no_recognisable_header_is_refused(): void
    {
        $this->attemptAuthenticate();

        $branch = $this->branch( 'G' );

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            sku: 'NOHEAD-1',
            units: [ 'ชิ้น' => 1.0 ],
            onBaseUnit: false
        );

        $row = $this->stockRow( $product, $units[ 'ชิ้น' ], $branch->id, 20.0 );

        $this->artisan( 'stock:import-csv', [
            'file' => $this->writeSheet( [ 'NOHEAD-1,สินค้า,50' ], 'code,name,onhand' ),
            '--branch' => $branch->id,
        ] )->assertFailed();

        $this->assertSame( 20.0, $this->quantityOf( $row->id ) );
    }

    /**
     * A trial run reports without writing, so a sheet can be checked before it is trusted.
     */
    public function test_a_trial_run_writes_nothing(): void
    {
        $this->attemptAuthenticate();

        $branch = $this->branch( 'I' );

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            sku: 'DRY-1',
            units: [ 'ชิ้น' => 1.0 ],
            onBaseUnit: false
        );

        $row = $this->stockRow( $product, $units[ 'ชิ้น' ], $branch->id, 14.0 );

        $this->artisan( 'stock:import-csv', [
            'file' => $this->writeSheet( [ 'DRY-1,สินค้าทดลอง,300' ] ),
            '--branch' => $branch->id,
            '--dry-run' => true,
        ] )->assertSuccessful();

        $this->assertSame( 14.0, $this->quantityOf( $row->id ) );
    }

    /**
     * Every movement is written to ประวัติการปรับปรุง, so a figure that turns out wrong can be traced
     * to the sheet that set it.
     */
    public function test_the_movement_is_recorded_in_the_history(): void
    {
        $this->attemptAuthenticate();

        $branch = $this->branch( 'J' );

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            sku: 'HIST-1',
            units: [ 'ชิ้น' => 1.0 ],
            onBaseUnit: false
        );

        $this->stockRow( $product, $units[ 'ชิ้น' ], $branch->id, 10.0 );

        $before = ProductHistory::where( 'product_id', $product->id )->count();

        $this->artisan( 'stock:import-csv', [
            'file' => $this->writeSheet( [ 'HIST-1,สินค้ามีประวัติ,4' ] ),
            '--branch' => $branch->id,
        ] )->assertSuccessful();

        $this->assertGreaterThan(
            $before,
            ProductHistory::where( 'product_id', $product->id )->count(),
            'Setting a figure has to leave a trace.'
        );

        $entry = ProductHistory::where( 'product_id', $product->id )->orderByDesc( 'id' )->first();

        $this->assertNotNull( $entry );
        $this->assertSame( 6.0, (float) $entry->quantity, 'Ten counted down to four is a removal of six.' );
        $this->assertSame( 4.0, (float) $entry->after_quantity );
    }

    /**
     * A code the catalogue does not carry is counted and passed over, so one unknown line does not
     * stop the sheet.
     */
    public function test_an_unknown_code_does_not_stop_the_sheet(): void
    {
        $this->attemptAuthenticate();

        $branch = $this->branch( 'K' );

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            sku: 'KNOWN-1',
            units: [ 'ชิ้น' => 1.0 ],
            onBaseUnit: false
        );

        $row = $this->stockRow( $product, $units[ 'ชิ้น' ], $branch->id, 3.0 );

        $this->artisan( 'stock:import-csv', [
            'file' => $this->writeSheet( [
                'DOES-NOT-EXIST,สินค้าที่ไม่มีในระบบ,500',
                'KNOWN-1,สินค้าที่มีอยู่,18',
            ] ),
            '--branch' => $branch->id,
        ] )->assertSuccessful();

        $this->assertSame( 18.0, $this->quantityOf( $row->id ), 'The known line was blocked by the unknown one.' );
    }

    /**
     * A figure written with a thousands separator is read as a number, because that is how a
     * spreadsheet exports it.
     */
    public function test_a_figure_written_with_a_thousands_separator_is_read(): void
    {
        $this->attemptAuthenticate();

        $branch = $this->branch( 'L' );

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            sku: 'COMMA-1',
            units: [ 'ชิ้น' => 1.0 ],
            onBaseUnit: false
        );

        $row = $this->stockRow( $product, $units[ 'ชิ้น' ], $branch->id, 1.0 );

        $this->artisan( 'stock:import-csv', [
            'file' => $this->writeSheet( [ 'COMMA-1,สินค้าเลขมีลูกน้ำ,"2,450"' ] ),
            '--branch' => $branch->id,
        ] )->assertSuccessful();

        $this->assertSame( 2450.0, $this->quantityOf( $row->id ) );
    }
}
