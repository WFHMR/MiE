<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\Unit;
use App\Models\UnitGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClearAndImportProductsSeeder extends Seeder
{
    // Cache for lookup tables to improve performance
    private array $categoryCache = [];

    private array $providerCache = [];

    private array $unitCache = [];

    private ?int $defaultUnitGroupId = null;

    /**
     * Clear all existing products and related data
     */
    private function clearExistingData(): void
    {
        $this->command->info( '🗑️  Clearing existing product data...' );

        DB::table( 'nexopos_products_histories' )->delete();
        DB::table( 'nexopos_products_unit_quantities' )->delete();
        DB::table( 'nexopos_products_taxes' )->delete();
        DB::table( 'nexopos_products_metas' )->delete();
        DB::table( 'nexopos_products_galleries' )->delete();
        DB::table( 'nexopos_products' )->delete();

        $this->command->info( '✅ All product data cleared' );
    }

    /**
     * Get or create category (with cache)
     */
    private function getOrCreateCategory( string $categoryCode, ?string $subcategoryCode = null ): int
    {
        $categoryName = trim( $categoryCode ) ?: 'Uncategorized';

        // Check cache first
        if ( isset( $this->categoryCache[$categoryName] ) ) {
            return $this->categoryCache[$categoryName];
        }

        $category = ProductCategory::firstOrCreate(
            ['name' => $categoryName],
            [
                'author_id' => 1,
                'description' => "Category: {$categoryName}",
            ]
        );

        // Cache the result
        $this->categoryCache[$categoryName] = $category->id;

        return $category->id;
    }

    /**
     * Get or create provider (supplier) with cache
     */
    private function getOrCreateProvider( string $providerCode, string $providerName ): ?int
    {
        $providerCode = trim( $providerCode );

        if ( empty( $providerCode ) ) {
            return null;
        }

        // Check cache first
        if ( isset( $this->providerCache[$providerCode] ) ) {
            return $this->providerCache[$providerCode];
        }

        $provider = Provider::firstOrCreate(
            ['email' => $providerCode . '@supplier.local'],
            [
                'first_name' => $providerName ?: $providerCode,
                'last_name' => $providerCode,
                'author_id' => 1,
                'description' => "Provider: {$providerName}",
            ]
        );

        // Cache the result
        $this->providerCache[$providerCode] = $provider->id;

        return $provider->id;
    }

    /**
     * Get or create unit with cache
     * Returns array: ['unit_id' => int, 'unit_group_id' => int]
     */
    private function getOrCreateUnit( string $unitName ): array
    {
        $unitName = trim( $unitName );

        // Check cache first
        if ( isset( $this->unitCache[$unitName] ) ) {
            return $this->unitCache[$unitName];
        }

        // Get or create default unit group (cache the ID)
        if ( $this->defaultUnitGroupId === null ) {
            $unitGroup = UnitGroup::firstOrCreate(
                ['name' => 'Default'],
                ['author_id' => 1]
            );
            $this->defaultUnitGroupId = $unitGroup->id;
        }

        // Generate identifier: use transliteration or fallback to md5 for Thai chars
        $identifier = Str::slug( Str::ascii( $unitName ) );
        if ( empty( $identifier ) ) {
            // Fallback for Thai characters: use first 10 chars of md5 hash
            $identifier = substr( md5( $unitName ), 0, 10 );
        }

        $unit = Unit::firstOrCreate(
            ['name' => $unitName],
            [
                'identifier' => $identifier,
                'author_id' => 1,
                'value' => 1,
                'base_unit' => true,
                'group_id' => $this->defaultUnitGroupId,
            ]
        );

        $result = [
            'unit_id' => $unit->id,
            'unit_group_id' => $this->defaultUnitGroupId,
        ];

        // Cache the result
        $this->unitCache[$unitName] = $result;

        return $result;
    }

    /**
     * Import products from CSV file
     *
     * @param string   $filepath Path to CSV file
     * @param int|null $branchId Branch ID for stock isolation
     * @param int      $skipRows Number of header rows to skip (3 for ทะเบียนสินค้า, 2 for สาขาท่าช้าง)
     */
    private function importFromCSV( string $filepath, ?int $branchId = null, int $skipRows = 3 ): void
    {
        if ( ! file_exists( $filepath ) ) {
            $this->command->error( "❌ File not found: {$filepath}" );

            return;
        }

        $this->command->info( "📂 Reading: {$filepath}" );

        // Detect encoding and convert to UTF-8 if needed
        $content = file_get_contents( $filepath );
        $encoding = mb_detect_encoding( $content, ['UTF-8', 'TIS-620', 'ISO-8859-1', 'Windows-1252'], true );

        if ( $encoding && $encoding !== 'UTF-8' ) {
            $this->command->warn( "⚠️  Converting from {$encoding} to UTF-8" );
            $content = mb_convert_encoding( $content, 'UTF-8', $encoding );
            $tempFile = tempnam( sys_get_temp_dir(), 'csv_' );
            file_put_contents( $tempFile, $content );
            $filepath = $tempFile;
        }

        $file = fopen( $filepath, 'r' );
        $rowNum = 0;
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $progressCounter = 0;

        while ( ( $row = fgetcsv( $file ) ) !== false ) {
            $rowNum++;

            // Skip header rows (configurable per file)
            if ( $rowNum <= $skipRows ) {
                continue;
            }

            // Skip separator rows (รหัสกลุ่มหลัก lines or empty rows)
            if ( isset( $row[0] ) && (
                str_contains( $row[0], 'รหัสกลุ่มหลัก' ) ||
                str_contains( $row[0], 'รายการสินค้า' ) ||
                empty( trim( $row[0] ) )
            ) ) {
                $skipped++;

                continue;
            }

            // Skip rows where name (column 2) is empty or starts with "--"
            if ( ! isset( $row[2] ) || empty( trim( $row[2] ) ) || str_starts_with( trim( $row[2] ), '--' ) ) {
                $skipped++;

                continue;
            }

            try {
                DB::transaction( function () use ( $row, &$imported, &$skipped ) {
                    $data = [
                        'stock_code' => trim( $row[0] ?? '' ),
                        'barcode' => trim( $row[1] ?? '' ),
                        'name' => trim( $row[2] ?? '' ),
                        'packaging' => trim( $row[3] ?? '' ),
                        'size' => trim( $row[4] ?? '' ),
                        'size_unit' => trim( $row[5] ?? '' ),
                        'pack_unit' => trim( $row[6] ?? '' ),
                        'stock_unit' => trim( $row[7] ?? '' ),
                        'sale_unit' => trim( $row[8] ?? '' ),
                        'main_category' => trim( $row[9] ?? '' ),
                        'sub_category' => trim( $row[10] ?? '' ),
                        'supplier_code' => trim( $row[11] ?? '' ),
                        'supplier_name' => trim( $row[12] ?? '' ),
                        'cost_price' => floatval( $row[13] ?? 0 ),
                        'sale_price' => floatval( $row[14] ?? 0 ),
                        'quantity' => isset( $row[15] ) ? intval( $row[15] ) : 0,
                    ];

                    // Skip invalid rows
                    if ( empty( $data['barcode'] ) || empty( $data['name'] ) ) {
                        $skipped++;

                        return;
                    }

                    // Get or create category
                    $categoryId = $this->getOrCreateCategory( $data['main_category'], $data['sub_category'] );

                    // Get or create provider
                    $providerId = $this->getOrCreateProvider( $data['supplier_code'], $data['supplier_name'] );

                    // Get or create unit (returns ['unit_id' => int, 'unit_group_id' => int])
                    $unitInfo = $this->getOrCreateUnit( $data['sale_unit'] ?: 'ชิ้น' );

                    // Create or update product
                    $product = Product::updateOrCreate(
                        ['barcode' => $data['barcode']],
                        [
                            'name' => $data['name'],
                            'sku' => $data['stock_code'] ?: $data['barcode'],
                            'category_id' => $categoryId,
                            'unit_group' => $unitInfo['unit_group_id'],
                            'author_id' => 1,
                            'type' => 'product',
                            'product_type' => 'product',
                            'status' => 'available',
                            'stock_management' => 'enabled',
                            'barcode_type' => 'ean13',
                            'description' => $data['packaging'],
                        ]
                    );

                    // Link provider to product if exists
                    if ( $providerId ) {
                        DB::table( 'nexopos_products_metas' )->updateOrInsert(
                            [
                                'product_id' => $product->id,
                                'key' => 'provider_id',
                            ],
                            [
                                'value' => $providerId,
                                'author_id' => 1,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }

                    // Create unit quantity with branch isolation
                    ProductUnitQuantity::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'unit_id' => $unitInfo['unit_id'],
                        ],
                        [
                            'quantity' => $data['quantity'],
                            'sale_price' => $data['sale_price'],
                            'wholesale_price' => $data['cost_price'],
                            'custom_price' => $data['sale_price'],
                            'preview_url' => null,
                        ]
                    );

                    $imported++;
                } );

                $progressCounter++;

                // Show progress every 100 rows for better performance
                if ( $progressCounter % 100 === 0 ) {
                    $this->command->info( "   ... processed {$imported} products" );
                }

            } catch ( \Exception $e ) {
                $this->command->error( "❌ Row {$rowNum}: " . $e->getMessage() );
                $errors++;

                // Stop if too many errors
                if ( $errors > 50 ) {
                    $this->command->error( "🛑 Too many errors ({$errors}), stopping import." );
                    break;
                }
            }
        }

        fclose( $file );

        // Clean up temp file if created
        if ( isset( $tempFile ) && file_exists( $tempFile ) ) {
            unlink( $tempFile );
        }

        $this->command->info( "✅ Imported: {$imported} products" );
        if ( $skipped > 0 ) {
            $this->command->warn( "⚠️  Skipped: {$skipped} rows" );
        }
        if ( $errors > 0 ) {
            $this->command->error( "❌ Errors: {$errors} rows" );
        }
    }

    /**
     * Run the seeder
     */
    public function run(): void
    {
        $this->command->info( '🚀 Starting Product Import...' );

        // Step 1: Clear existing data
        $this->clearExistingData();

        // Step 2: Get or create MAIN branch
        $mainBranch = DB::table( 'nexopos_branches' )->where( 'code', 'MAIN' )->first();

        if ( ! $mainBranch ) {
            $this->command->warn( '⚠️  MAIN branch not found, creating...' );
            DB::table( 'nexopos_branches' )->insert( [
                'code' => 'MAIN',
                'name' => 'สาขาหลัก',
                'is_active' => 1,
                'is_main' => 1,
                'is_main_warehouse' => 1,
                'author_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ] );
            $mainBranch = DB::table( 'nexopos_branches' )->where( 'code', 'MAIN' )->first();
        }

        // Step 3: Get or create ท่าช้าง branch
        $thachangBranch = DB::table( 'nexopos_branches' )->where( 'name', 'LIKE', '%ท่าช้าง%' )->first();

        if ( ! $thachangBranch ) {
            $this->command->warn( '⚠️  Branch "ท่าช้าง" not found, creating...' );
            DB::table( 'nexopos_branches' )->insert( [
                'code' => 'THACHANG',
                'name' => 'สาขาท่าช้าง',
                'is_active' => 1,
                'is_main' => 0,
                'is_main_warehouse' => 0,
                'author_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ] );
            $thachangBranch = DB::table( 'nexopos_branches' )->where( 'code', 'THACHANG' )->first();
        }

        // Validate branches exist
        if ( ! $mainBranch || ! $thachangBranch ) {
            $this->command->error( '❌ Failed to create or find required branches' );

            return;
        }

        $this->command->info( "✅ Using branches: MAIN (ID: {$mainBranch->id}), ท่าช้าง (ID: {$thachangBranch->id})" );

        // Step 4: Import from ทะเบียนสินค้า (Master list)
        $this->command->info( '📥 Importing from: Template - ทะเบียนสินค้า.csv' );
        $this->importFromCSV(
            base_path( 'Template - ทะเบียนสินค้า.csv' ),
            $mainBranch->id,
            3  // Skip 3 rows: header, date, column names
        );

        // Step 5: Import from สาขาท่าช้าง (with quantity)
        $this->command->info( '📥 Importing from: Template - สาขาท่าช้าง.csv' );
        $this->importFromCSV(
            base_path( 'Template - สาขาท่าช้าง.csv' ),
            $thachangBranch->id,
            2  // Skip 2 rows: header, column names
        );

        $this->command->info( '🎉 Import complete!' );

        // Show final statistics
        $totalProducts = DB::table( 'nexopos_products' )->count();
        $totalQuantities = DB::table( 'nexopos_products_unit_quantities' )->count();

        $this->command->info( '📊 Final Statistics:' );
        $this->command->info( "   - Total Products: {$totalProducts}" );
        $this->command->info( "   - Total Unit Quantities: {$totalQuantities}" );
    }
}
