<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class AssignTaxToProducts extends Command
{
    protected $signature = 'products:assign-tax 
                            {--tax-group=2 : Tax Group ID to assign (default: 2 for VAT 7%)}
                            {--tax-type=inclusive : Tax type: inclusive or exclusive}';

    protected $description = 'Assign tax_group_id to all products that do not have one';

    public function handle(): int
    {
        $taxGroup = $this->option('tax-group');
        $taxType = $this->option('tax-type');

        $this->info("Assigning tax_group_id = {$taxGroup} and tax_type = {$taxType} to products...");

        // First, assign tax_group_id to products without it
        $updated = DB::table('nexopos_products')
            ->whereNull('tax_group_id')
            ->update([
                'tax_group_id' => $taxGroup,
                'tax_type' => $taxType,
                'updated_at' => now(),
            ]);

        $this->info("✓ Updated {$updated} products with tax group {$taxGroup}");

        // Second, fix empty tax_type for products that have tax_group_id
        $fixedType = DB::table('nexopos_products')
            ->whereNotNull('tax_group_id')
            ->where(function($query) {
                $query->whereNull('tax_type')
                      ->orWhere('tax_type', '');
            })
            ->update([
                'tax_type' => $taxType,
                'updated_at' => now(),
            ]);

        $this->info("✓ Fixed tax_type for {$fixedType} products");

        // Show sample
        $sample = Product::whereNotNull('tax_group_id')->limit(3)->get(['id', 'name', 'tax_group_id', 'tax_type']);
        
        $this->newLine();
        $this->info("Sample products:");
        foreach ($sample as $product) {
            $this->line("  ID: {$product->id} | {$product->name} | Tax Group: {$product->tax_group_id} | Type: {$product->tax_type}");
        }

        return self::SUCCESS;
    }
}
