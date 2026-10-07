<?php

namespace Modules\ProcurementImport\Services;

/**
 * Validation Service - ตรวจสอบความถูกต้องของข้อมูล CSV
 */
class ValidationService
{
    protected array $errors = [];
    protected array $warnings = [];
    
    /**
     * Validate CSV row data
     * 
     * @param array $row Row data
     * @param int $rowNumber Row number in CSV
     * @param bool $isStockImport Whether this is a stock import (quantity required)
     */
    public function validateRow(array $row, int $rowNumber, bool $isStockImport = true): array
    {
        $this->errors = [];
        $this->warnings = [];
        
        // Required fields
        if (empty($row['stock_code'])) {
            $this->errors[] = "Row {$rowNumber}: รหัสสินค้าว่างเปล่า";
        }
        
        if (empty($row['product_name'])) {
            $this->errors[] = "Row {$rowNumber}: ชื่อสินค้าว่างเปล่า";
        }
        
        // Numeric validations
        if ($row['cost_price'] < 0) {
            $this->errors[] = "Row {$rowNumber}: ราคาทุนติดลบ ({$row['cost_price']})";
        }
        
        if ($row['sale_price_vat'] < 0) {
            $this->errors[] = "Row {$rowNumber}: ราคาขายติดลบ ({$row['sale_price_vat']})";
        }
        
        // Quantity validation - only check if this is a stock import
        if ($isStockImport && isset($row['quantity']) && $row['quantity'] < 0) {
            $this->warnings[] = "Row {$rowNumber}: จำนวนสต็อกติดลบ ({$row['quantity']})";
        }
        
        // Business logic validations
        if ($row['sale_price_vat'] > 0 && $row['cost_price'] > $row['sale_price_vat']) {
            $this->warnings[] = "Row {$rowNumber}: ราคาทุนสูงกว่าราคาขาย (ทุน: {$row['cost_price']}, ขาย: {$row['sale_price_vat']})";
        }
        
        // Supplier validation
        if (empty($row['supplier_code'])) {
            $this->warnings[] = "Row {$rowNumber}: ไม่มีรหัสซัพพลายเออร์";
        }
        
        // Category validation
        if (empty($row['category_main']) || empty($row['category_sub'])) {
            $this->warnings[] = "Row {$rowNumber}: หมวดหมู่ไม่สมบูรณ์";
        }
        
        return [
            'valid' => empty($this->errors),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
    
    /**
     * Validate entire dataset
     * 
     * @param array $data CSV rows
     * @param bool $isStockImport Whether this is a stock import
     */
    public function validateDataset(array $data, bool $isStockImport = true): array
    {
        $totalErrors = [];
        $totalWarnings = [];
        $validRows = 0;
        
        foreach ($data as $row) {
            $result = $this->validateRow($row, $row['row_number'], $isStockImport);
            
            if ($result['valid']) {
                $validRows++;
            }
            
            $totalErrors = array_merge($totalErrors, $result['errors']);
            $totalWarnings = array_merge($totalWarnings, $result['warnings']);
        }
        
        return [
            'total_rows' => count($data),
            'valid_rows' => $validRows,
            'invalid_rows' => count($data) - $validRows,
            'errors' => $totalErrors,
            'warnings' => $totalWarnings,
            'can_import' => empty($totalErrors),
        ];
    }
    
    /**
     * Check for duplicate SKUs in dataset
     */
    public function checkDuplicates(array $data): array
    {
        $skuMap = [];
        $duplicates = [];
        
        foreach ($data as $row) {
            $sku = $row['stock_code'];
            
            if (isset($skuMap[$sku])) {
                $duplicates[] = [
                    'sku' => $sku,
                    'rows' => [$skuMap[$sku], $row['row_number']],
                ];
            } else {
                $skuMap[$sku] = $row['row_number'];
            }
        }
        
        return $duplicates;
    }
}
