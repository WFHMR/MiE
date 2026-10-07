<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LocksInventoryImport;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sets branch stock figures from a counted sheet, keyed on รหัสสต็อก.
 *
 * This command used to write the figure with one statement:
 *
 *     ProductUnitQuantity::where( 'product_id', $product->id )->update( [ 'quantity' => $quantity ] );
 *
 * No unit, no branch. On goods sold through a unit ladder that put the same figure onto every rung,
 * so a product stocked as three hundred pieces also read three hundred packs and three hundred
 * cases — the same shelf counted three times over, and sellable three times over. On a shop with
 * two branches it wrote both. It was the one importer with no test covering it, and it undid exactly
 * what every other importer here is careful to arrange.
 *
 * It now finds the single row that actually bears the stock and sets that, through the stock service
 * so the movement is locked, recorded in ประวัติการปรับปรุง, and attributable. Where it cannot tell
 * which row is meant it says so and leaves the goods alone, because a stock figure written to the
 * wrong rung is not visible until someone counts the shelf again.
 *
 * The header is found by reading it rather than by skipping a fixed number of lines. The old version
 * threw away the first three rows whatever they were, so a file with two title lines had its first
 * product silently eaten and one with four read a title as a product.
 */
class ImportStockFromCsv extends Command
{
    use LocksInventoryImport;

    protected $signature = 'stock:import-csv
        {file : Path to the counted sheet}
        {--branch= : Branch id the figures belong to, required once stock is split by branch}
        {--dry-run : Report what would change without writing}';

    protected $description = 'ตั้งยอดคงเหลือของสาขาจากไฟล์นับสต๊อก โดยเขียนลงหน่วยที่เก็บสต๊อกเท่านั้น';

    /**
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'stock' => [ 'รหัสสต็อก' ],
        'quantity' => [ 'คงเหลือ', 'จำนวน', 'จำนวนคงเหลือ' ],
    ];

    public function handle( ProductService $productService ): int
    {
        $path = (string) $this->argument( 'file' );

        if ( ! is_readable( $path ) ) {
            $this->error( "อ่านไฟล์ไม่ได้: {$path}" );

            return self::FAILURE;
        }

        $branchId = $this->option( 'branch' ) !== null && $this->option( 'branch' ) !== ''
            ? (int) $this->option( 'branch' )
            : null;

        if ( $branchId !== null && ! DB::table( 'nexopos_branches' )->where( 'id', $branchId )->exists() ) {
            $this->error( "ไม่พบสาขา id={$branchId}" );

            return self::FAILURE;
        }

        /**
         * Refused up front rather than per product. A run that guessed the branch would have to be
         * undone product by product, and the person running it would not know which ones to look at.
         */
        if ( $branchId === null && ProductUnitQuantity::whereNotNull( 'branch_id' )->exists() ) {
            $this->error( 'ระบบเก็บสต๊อกแยกตามสาขา ต้องระบุ --branch=<id> ว่าไฟล์นี้เป็นยอดของสาขาไหน' );

            return self::FAILURE;
        }

        $rows = $this->readRows( $path );

        if ( $rows === null ) {
            return self::FAILURE;
        }

        if ( $rows === [] ) {
            $this->warn( 'ไม่พบแถวข้อมูลในไฟล์' );

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option( 'dry-run' );

        $this->info( sprintf( 'อ่านได้ %d แถว%s', count( $rows ), $dryRun ? ' (ทดลอง ไม่เขียนจริง)' : '' ) );

        $updated = 0;
        $unchanged = 0;
        $notFound = 0;
        $ambiguous = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar( count( $rows ) );
        $bar->start();

        foreach ( $rows as $row ) {
            $bar->advance();

            $product = Product::where( 'sku', $row[ 'stock' ] )->first();

            if ( ! $product instanceof Product ) {
                $notFound++;

                continue;
            }

            $target = $this->stockBearingRow( $product, $branchId );

            if ( $target === null ) {
                $ambiguous++;
                $this->newLine();
                $this->warn( sprintf(
                    'ข้าม %s (%s): บอกไม่ได้ว่าต้องเขียนยอดลงหน่วยไหน',
                    $row[ 'stock' ],
                    mb_substr( (string) $product->name, 0, 40 )
                ) );

                continue;
            }

            if ( abs( (float) $target->quantity - $row[ 'quantity' ] ) < 0.00001 ) {
                $unchanged++;

                continue;
            }

            if ( $dryRun ) {
                $updated++;

                continue;
            }

            try {
                $productService->stockAdjustment( ProductHistory::ACTION_SET, [
                    'product_id' => $product->id,
                    'unit_id' => $target->unit_id,
                    'quantity' => $row[ 'quantity' ],
                    'unit_price' => (float) ( $target->cogs ?: 0 ),
                    'branch_id' => $target->branch_id !== null ? (int) $target->branch_id : $branchId,
                    'author' => 1,
                    'description' => 'ตั้งยอดคงเหลือจากไฟล์นับสต๊อก',
                ] );

                $updated++;
            } catch ( \Throwable $e ) {
                $failed++;
                $this->newLine();
                $this->error( sprintf( '%s: %s', $row[ 'stock' ], $e->getMessage() ) );
            }
        }

        $bar->finish();
        $this->newLine( 2 );

        $this->info( sprintf( 'ตั้งยอดใหม่        : %d', $updated ) );
        $this->info( sprintf( 'ยอดตรงอยู่แล้ว     : %d', $unchanged ) );

        if ( $notFound > 0 ) {
            $this->warn( sprintf( 'ไม่พบรหัสสต็อก     : %d', $notFound ) );
        }

        if ( $ambiguous > 0 ) {
            $this->warn( sprintf( 'ข้ามเพราะไม่ชัดเจน : %d — ตั้งยอดให้สินค้าเหล่านี้ผ่านหน้าอัพโหลด CSV แทน', $ambiguous ) );
        }

        if ( $failed > 0 ) {
            $this->error( sprintf( 'ผิดพลาด           : %d', $failed ) );
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The one row a counted figure belongs on.
     *
     * Goods that keep their stock on a base unit have exactly one row that means anything; the rest
     * are price labels holding zero on purpose. Goods with a single unit have only the one row. Any
     * other shape — several units and no flag to say which bears the stock — is not something this
     * command may decide, because writing to the wrong one is invisible until the next count.
     */
    private function stockBearingRow( Product $product, ?int $branchId ): ?ProductUnitQuantity
    {
        $rows = ProductUnitQuantity::where( 'product_id', $product->id )
            ->when( $branchId !== null, fn ( $query ) => $query->where( 'branch_id', $branchId ) )
            ->orderBy( 'id' )
            ->get();

        if ( $rows->isEmpty() ) {
            return null;
        }

        if ( $rows->count() === 1 ) {
            return $rows->first();
        }

        if ( (bool) $product->stock_on_base_unit !== true ) {
            return null;
        }

        $baseUnit = Unit::where( 'group_id', $product->unit_group )
            ->where( 'base_unit', true )
            ->first();

        if ( ! $baseUnit instanceof Unit ) {
            return null;
        }

        return $rows->firstWhere( 'unit_id', $baseUnit->id );
    }

    /**
     * @return list<array{stock: string, quantity: float}>|null null when the header cannot be placed
     */
    private function readRows( string $path ): ?array
    {
        $handle = fopen( $path, 'r' );

        if ( $handle === false ) {
            $this->error( 'เปิดไฟล์ไม่ได้' );

            return null;
        }

        $lines = [];

        while ( ( $line = fgetcsv( $handle ) ) !== false ) {
            $lines[] = $line;
        }

        fclose( $handle );

        if ( isset( $lines[ 0 ][ 0 ] ) && str_starts_with( (string) $lines[ 0 ][ 0 ], "\xEF\xBB\xBF" ) ) {
            $lines[ 0 ][ 0 ] = substr( (string) $lines[ 0 ][ 0 ], 3 );
        }

        $headerIndex = null;
        $map = [];

        foreach ( $lines as $index => $line ) {
            $candidate = [];

            foreach ( $line as $column => $cell ) {
                $cell = trim( (string) $cell );

                foreach ( self::COLUMNS as $meaning => $names ) {
                    if ( ! isset( $candidate[ $meaning ] ) && in_array( $cell, $names, true ) ) {
                        $candidate[ $meaning ] = $column;
                    }
                }
            }

            if ( isset( $candidate[ 'stock' ], $candidate[ 'quantity' ] ) ) {
                $headerIndex = $index;
                $map = $candidate;

                break;
            }
        }

        if ( $headerIndex === null ) {
            $this->error( 'ไม่พบหัวตาราง — ไฟล์ต้องมีคอลัมน์ รหัสสต็อก และ คงเหลือ (หรือ จำนวน)' );
            $this->line( 'เดิมคำสั่งนี้ข้าม 3 บรรทัดแรกโดยไม่ดูว่าเป็นอะไร ไฟล์ที่มีบรรทัดหัวเรื่องไม่พอดีจึงอ่านผิดเงียบ ๆ' );

            return null;
        }

        $rows = [];

        foreach ( array_slice( $lines, $headerIndex + 1 ) as $line ) {
            $stock = trim( (string) ( $line[ $map[ 'stock' ] ] ?? '' ) );
            $raw = trim( (string) ( $line[ $map[ 'quantity' ] ] ?? '' ) );

            if ( $stock === '' || $raw === '' ) {
                continue;
            }

            $cleaned = str_replace( [ ',', ' ' ], '', $raw );

            if ( ! is_numeric( $cleaned ) ) {
                continue;
            }

            $rows[] = [ 'stock' => $stock, 'quantity' => (float) $cleaned ];
        }

        return $rows;
    }
}
