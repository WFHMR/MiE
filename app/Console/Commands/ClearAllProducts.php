<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearAllProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:clear-all {--force : Force the operation without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all products, stock histories, and unit quantities from the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('This will DELETE ALL products, stock histories, and unit quantities. Are you sure?')) {
            $this->info('Operation cancelled.');

            return self::FAILURE;
        }

        $this->info('Starting deletion process...');

        // Get all tables related to products
        $tables = [
            'nexopos_products_histories',
            'nexopos_products',
        ];

        // Count and delete from each table
        foreach ($tables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $count = DB::table($table)->count();
                $this->info("Found {$count} records in {$table}");
                
                DB::table($table)->delete();
                $this->info("✓ Deleted all records from {$table}");
            } else {
                $this->warn("⚠ Table {$table} does not exist, skipping...");
            }
        }

        $this->newLine();
        $this->info('All product data has been cleared successfully!');
        $this->info('You can now import fresh data from CSV.');

        return self::SUCCESS;
    }
}
