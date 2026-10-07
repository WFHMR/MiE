<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Option;

class EnableVatCalculation extends Command
{
    protected $signature = 'pos:enable-vat 
                            {--mode=variable_vat : VAT mode: flat_vat, variable_vat, or products_vat}
                            {--tax-group=2 : Tax group ID to use}
                            {--tax-type=inclusive : Tax type: inclusive or exclusive}
                            {--price-mode=gross_prices : Price mode: net_prices or gross_prices}';

    protected $description = 'Enable VAT calculation in POS system';

    public function handle(): int
    {
        $mode = $this->option('mode');
        $taxGroup = $this->option('tax-group');
        $taxType = $this->option('tax-type');
        $priceMode = $this->option('price-mode');

        // Check current settings
        $this->info("Current Settings:");
        $this->line("  ns_pos_vat: " . ns()->option->get('ns_pos_vat', 'disabled'));
        $this->line("  ns_pos_tax_group: " . (ns()->option->get('ns_pos_tax_group') ?: 'not set'));
        $this->line("  ns_pos_tax_type: " . (ns()->option->get('ns_pos_tax_type') ?: 'not set'));
        $this->line("  ns_pos_prefered_price: " . (ns()->option->get('ns_pos_prefered_price') ?: 'not set'));
        $this->newLine();

        // Update settings
        ns()->option->set('ns_pos_vat', $mode);
        ns()->option->set('ns_pos_tax_group', $taxGroup);
        ns()->option->set('ns_pos_tax_type', $taxType);
        ns()->option->set('ns_pos_prefered_price', $priceMode);
        
        $this->info("✓ VAT calculation configured:");
        $this->line("  Mode: {$mode}");
        $this->line("  Tax Group ID: {$taxGroup}");
        $this->line("  Tax Type: {$taxType}");
        $this->line("  Price Mode: {$priceMode}");
        $this->newLine();
        
        $this->info("VAT Modes:");
        $this->line("  • flat_vat: Use a single tax rate from settings");
        $this->line("  • variable_vat: Calculate tax per product based on tax_group_id");
        $this->line("  • products_vat: Use tax settings from each product");
        $this->newLine();
        
        $this->info("Price Modes:");
        $this->line("  • net_prices: Subtotal from prices WITHOUT tax (use with exclusive)");
        $this->line("  • gross_prices: Subtotal from prices WITH tax (use with inclusive)");
        $this->newLine();

        // Verify
        $this->info("Verified Settings:");
        $this->line("  ns_pos_vat = " . ns()->option->get('ns_pos_vat'));
        $this->line("  ns_pos_tax_group = " . ns()->option->get('ns_pos_tax_group'));
        $this->line("  ns_pos_tax_type = " . ns()->option->get('ns_pos_tax_type'));
        $this->line("  ns_pos_prefered_price = " . ns()->option->get('ns_pos_prefered_price'));
        $this->newLine();

        if (ns()->option->get('ns_pos_vat') !== 'disabled') {
            $this->info("✓ VAT is now enabled! Tax will be calculated on new orders.");
        }

        return self::SUCCESS;
    }
}
