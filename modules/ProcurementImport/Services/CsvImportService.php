<?php

namespace Modules\ProcurementImport\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\NexoPOS\ProcurementImport\Models\CsvImportBatch;
use Modules\NexoPOS\ProcurementImport\Models\CsvImportRow;

class CsvImportService
{
    public function __construct(
        private CsvParserService $parserService,
        private ValidationService $validationService,
        private SupplierService $supplierService,
        private CategoryService $categoryService,
        private TaxService $taxService,
        private UnitService $unitService,
        private ProductService $productService,
        private StockService $stockService
    ) {}

    /**
     * Parse CSV file and create import batch
     *
     * @param string $filePath
     * @param string $filename
     * @param int $userId
     * @param int $branchId
     * @return CsvImportBatch
     */
    public function createBatchFromCsv(string $filePath, string $filename, int $userId, int $branchId): CsvImportBatch
    {
        // Parse CSV using CsvParserService - returns ['type' => 'stock'|'product', 'rows' => [...]]
        $parseResult = $this->parserService->parse($filePath);
        $rows = $parseResult['rows'];
        $importType = $parseResult['type'];
        
        Log::info("Creating import batch", [
            'type' => $importType,
            'total_rows' => count($rows),
            'branch_id' => $branchId,
        ]);
        
        // Create batch
        $batch = CsvImportBatch::create([
            'branch_id' => $branchId,
            'user_id' => $userId,
            'filename' => $filename,
            'status' => 'pending',
            'total_rows' => count($rows),
            'import_type' => $importType, // 'stock' or 'product'
            'started_at' => now(),
        ]);
        
        // Create row records
        foreach ($rows as $index => $rowData) {
            CsvImportRow::create([
                'batch_id' => $batch->id,
                'row_number' => $rowData['row_number'],
                'status' => 'pending',
                'row_data' => $rowData,
            ]);
        }
        
        return $batch;
    }

    /**
     * Process a single CSV import batch
     *
     * @param CsvImportBatch $batch
     * @return void
     */
    public function processBatch(CsvImportBatch $batch): void
    {
        $batch->update(['status' => 'processing']);
        
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        
        // Get import type from batch
        $isStockImport = ($batch->import_type ?? 'stock') === 'stock';
        
        Log::info("Processing batch", [
            'batch_id' => $batch->id,
            'import_type' => $batch->import_type,
            'is_stock_import' => $isStockImport,
        ]);
        
        // Clear service caches for this import session
        $this->supplierService->clearCache();
        $this->categoryService->clearCache();
        $this->unitService->clearCache();
        
        foreach ($batch->rows()->where('status', 'pending')->cursor() as $row) {
            try {
                DB::beginTransaction();
                
                $this->processRow($row, $batch->branch_id, $isStockImport);
                
                $row->update(['status' => 'success']);
                $successCount++;
                
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                
                $row->update([
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                ]);
                
                $errorCount++;
                $errors[] = "Row {$row->row_number}: {$e->getMessage()}";
                
                Log::error('CSV Import Row Failed', [
                    'batch_id' => $batch->id,
                    'row_number' => $row->row_number,
                    'error' => $e->getMessage(),
                    'row_data' => $row->row_data,
                ]);
            }
            
            // Update batch progress
            $batch->increment('processed_rows');
        }
        
        // Update batch status
        $batch->update([
            'status' => $errorCount === 0 ? 'completed' : 'failed',
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors,
            'completed_at' => now(),
        ]);
    }

    /**
     * Process a single CSV row
     *
     * @param CsvImportRow $row
     * @param int $branchId
     * @param bool $isStockImport Whether to update stock quantities
     * @return void
     * @throws \Exception
     */
    private function processRow(CsvImportRow $row, int $branchId, bool $isStockImport = true): void
    {
        $data = $row->row_data;
        
        // 1. Get or Create Supplier (if specified)
        $supplier = null;
        if (!empty($data['supplier_code']) || !empty($data['supplier_name'])) {
            $supplier = $this->supplierService->getOrCreateSupplier(
                $data['supplier_code'] ?? '',
                $data['supplier_name'] ?? '',
                $branchId
            );
        }
        
        // 2. Get or Create Category
        $category = $this->categoryService->getOrCreateCategory(
            $data['category_main'] ?? '',
            $data['category_sub'] ?? '',
            $branchId
        );
        
        // 3. Get or Create Unit
        $unit = $this->unitService->getOrCreateUnit(
            $data['stock_unit'] ?? 'ชิ้น',
            $branchId
        );
        
        // 4. Calculate tax-excluded prices using TaxService
        $buyingPriceExclTax = $this->taxService->reverseCalculateVAT($data['cost_price']);
        $sellingPriceExclTax = $this->taxService->reverseCalculateVAT($data['sale_price_vat']);
        
        // 5. Get or Create Product
        $product = $this->productService->getOrCreateProduct([
            'sku' => $data['stock_code'],
            'barcode' => $data['barcode'] ?? '',
            'name' => $data['product_name'],
            'category_id' => $category->id,
            'supplier_id' => $supplier?->id,
            'unit_id' => $unit->id,
            'buying_price' => $buyingPriceExclTax,
            'selling_price' => $sellingPriceExclTax,
            'branch_id' => $branchId,
        ]);
        
        // 6. Update Stock (only for stock imports)
        if ($isStockImport && isset($data['quantity']) && $data['quantity'] > 0) {
            $this->stockService->updateProductStock(
                $product,
                $unit,
                $branchId,
                $data['quantity'],
                $buyingPriceExclTax,
                $sellingPriceExclTax
            );
            
            Log::info("Stock updated", [
                'product_id' => $product->id,
                'sku' => $data['stock_code'],
                'quantity' => $data['quantity'],
                'branch_id' => $branchId,
            ]);
        } else {
            Log::info("Product created/updated (no stock)", [
                'product_id' => $product->id,
                'sku' => $data['stock_code'],
                'branch_id' => $branchId,
            ]);
        }
    }
}
