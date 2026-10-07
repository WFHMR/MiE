<?php

namespace Modules\ProcurementImport\Services;

use Illuminate\Support\Facades\Log;

/**
 * CSV Parser Service - แปลงไฟล์ CSV เป็น array
 * รองรับ UTF-8, TIS-620, ISO-8859-1
 */
class CsvParserService
{
    /**
     * Parse CSV file และข้ามบรรทัดแรก 2 บรรทัด (header)
     * 
     * Supports 2 formats:
     * 1. Product + Stock (สาขา): Has "จำนวน" column (16 columns)
     * 2. Product Only (ทะเบียน): No "จำนวน" column (15 columns)
     * 
     * @param string $filePath
     * @return array ['type' => 'stock'|'product', 'rows' => [...]]
     */
    public function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }
        
        // Detect encoding
        $content = file_get_contents($filePath);
        $encoding = $this->detectEncoding($content);
        
        // Convert to UTF-8 if needed
        if ($encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            file_put_contents($filePath, $content);
        }
        
        $file = fopen($filePath, 'r');
        if (!$file) {
            throw new \Exception("Cannot open file: {$filePath}");
        }
        
        // Skip first 2 rows (report header)
        fgetcsv($file); // Row 1: "รายงานรายการสินค้าที่ขายได้..."
        fgetcsv($file); // Row 2: "ระหว่างวันที่..." or headers for product-only
        
        // Row 3: Column headers
        $headers = fgetcsv($file);
        
        if (!$headers || count($headers) < 15) {
            fclose($file);
            throw new \Exception("Invalid CSV format. Expected at least 15 columns, got " . count($headers));
        }
        
        // Detect import type by checking if "จำนวน" column exists
        $hasQuantity = in_array('จำนวน', $headers);
        $importType = $hasQuantity ? 'stock' : 'product';
        $expectedColumns = $hasQuantity ? 16 : 15;
        
        Log::info("CSV Import Type: {$importType} (columns: " . count($headers) . ")");
        
        $data = [];
        $rowNumber = 3; // Start from row 3 (after 2 header rows)
        
        while (($row = fgetcsv($file)) !== false) {
            $rowNumber++;
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }
            
            // Skip rows that look like group headers (for product-only format)
            $firstCol = trim($row[0] ?? '');
            $thirdCol = trim($row[2] ?? '');
            if (strpos($firstCol, 'รหัสกลุ่ม') !== false || 
                strpos($thirdCol, '-- - ,') !== false) {
                Log::info("Row {$rowNumber}: Skipping group header");
                continue;
            }
            
            // Skip if less than minimum columns
            if (count($row) < $expectedColumns) {
                Log::warning("Row {$rowNumber}: Incomplete row (only " . count($row) . " columns)");
                continue;
            }
            
            $parsedRow = [
                'row_number' => $rowNumber,
                'stock_code' => trim($row[0] ?? ''),
                'barcode' => trim($row[1] ?? ''),
                'product_name' => trim($row[2] ?? ''),
                'pack_size' => trim($row[3] ?? ''),
                'pack_volume' => trim($row[4] ?? ''),
                'pack_unit' => trim($row[5] ?? ''),
                'pack_container' => trim($row[6] ?? ''),
                'stock_unit' => trim($row[7] ?? ''),
                'sale_unit' => trim($row[8] ?? ''),
                'category_main' => trim($row[9] ?? ''),
                'category_sub' => trim($row[10] ?? ''),
                'supplier_code' => trim($row[11] ?? ''),
                'supplier_name' => trim($row[12] ?? ''),
                'cost_price' => $this->parseNumber($row[13] ?? 0),
                'sale_price_vat' => $this->parseNumber($row[14] ?? 0),
            ];
            
            // Add quantity only if this is a stock import
            if ($hasQuantity && isset($row[15])) {
                $parsedRow['quantity'] = $this->parseNumber($row[15]);
            } else {
                $parsedRow['quantity'] = 0; // Default to 0 for product-only import
            }
            
            $data[] = $parsedRow;
        }
        
        fclose($file);
        
        return [
            'type' => $importType,
            'rows' => $data,
        ];
    }
    
    /**
     * Detect file encoding
     */
    protected function detectEncoding(string $content): string
    {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'TIS-620', 'ISO-8859-1', 'Windows-874'], true);
        
        if ($encoding === false) {
            // Default to UTF-8
            return 'UTF-8';
        }
        
        return $encoding;
    }
    
    /**
     * Parse number with comma separators
     * "1,616.67 " → 1616.67
     * "47,675" → 47675
     */
    public function parseNumber($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        
        // Remove commas and spaces
        $cleaned = trim(str_replace(',', '', $value));
        
        return is_numeric($cleaned) ? (float) $cleaned : 0;
    }
    
    /**
     * Convert Thai Buddhist date to Gregorian
     * "13/11/53" → "2010-11-13"
     */
    public function parseThaiDate(?string $dateStr): ?string
    {
        if (empty($dateStr)) {
            return null;
        }
        
        $parts = explode('/', $dateStr);
        if (count($parts) !== 3) {
            return null;
        }
        
        [$day, $month, $year] = $parts;
        
        // Convert 2-digit BE year to 4-digit CE year
        $beYear = intval($year) < 50 
            ? 2500 + intval($year)  // 49 or less = 25xx
            : 2400 + intval($year); // 50+ = 24xx
        
        $ceYear = $beYear - 543;
        
        return sprintf('%04d-%02d-%02d', $ceYear, intval($month), intval($day));
    }
}
