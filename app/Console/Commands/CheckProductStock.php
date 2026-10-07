<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckProductStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:check {search}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check product stock by name';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $search = $this->argument('search');
        
        $products = DB::table('nexopos_products')
            ->where('name', 'like', "%{$search}%")
            ->get();
        
        if ($products->isEmpty()) {
            $this->error("No products found matching: {$search}");
            return 1;
        }
        
        $this->info("Found " . $products->count() . " product(s):");
        $this->newLine();
        
        foreach ($products as $product) {
            $unitQty = DB::table('nexopos_products_unit_quantities')
                ->where('product_id', $product->id)
                ->first();
            
            $stock = $unitQty ? $unitQty->quantity : 'NO RECORD';
            
            $this->line("📦 {$product->name}");
            $this->line("   ID: {$product->id} | SKU: {$product->sku}");
            $this->line("   Stock: {$stock}");
            $this->newLine();
        }
        
        return 0;
    }
}
