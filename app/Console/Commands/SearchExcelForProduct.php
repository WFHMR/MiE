<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SearchExcelForProduct extends Command
{
    protected $signature = 'excel:search {file} {sku}';
    protected $description = 'Search for product in Excel by SKU';

    public function handle()
    {
        $filePath = $this->argument('file');
        $searchSku = $this->argument('sku');
        
        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        
        $this->info("Searching for SKU: {$searchSku}");
        $this->newLine();
        
        foreach ($rows as $index => $row) {
            if ($index < 2) continue; // Skip headers
            
            $sku = trim($row[1] ?? ''); // Column B
            $barcode = trim($row[2] ?? ''); // Column C
            $name = trim($row[3] ?? ''); // Column D
            $quantity = floatval($row[5] ?? 0); // Column F
            
            if ($sku === $searchSku || $barcode === $searchSku) {
                $this->info("✓ Found in Excel:");
                $this->line("  Row: " . ($index + 1));
                $this->line("  SKU: {$sku}");
                $this->line("  Barcode: {$barcode}");
                $this->line("  Name: {$name}");
                $this->line("  Quantity: {$quantity}");
                return 0;
            }
        }
        
        $this->warn("SKU not found in Excel file");
        return 1;
    }
}
