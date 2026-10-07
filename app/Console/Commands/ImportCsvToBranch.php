<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LocksInventoryImport;
use Illuminate\Console\Command;
use Modules\ProcurementImport\Services\CsvImportService;

class ImportCsvToBranch extends Command
{
    use LocksInventoryImport;

    protected $signature = 'csv:import-to-branch {filepath} {branch_id} {user_id=1}';

    protected $description = 'Import CSV file to a specific branch';

    public function handle( CsvImportService $csvImportService ): int
    {
        $filepath = $this->argument( 'filepath' );
        $branchId = (int) $this->argument( 'branch_id' );
        $userId = (int) $this->argument( 'user_id' );

        if ( ! file_exists( $filepath ) ) {
            $this->error( "File not found: {$filepath}" );

            return Command::FAILURE;
        }

        $this->info( "Importing CSV: {$filepath}" );
        $this->info( "Branch ID: {$branchId}" );
        $this->info( "User ID: {$userId}" );

        try {
            // Create batch - service requires: filePath, filename, userId, branchId
            $filename = basename( $filepath );
            $batch = $csvImportService->createBatchFromCsv( $filepath, $filename, $userId, $branchId );

            $this->info( "Batch created: ID={$batch->id}, Type={$batch->import_type}, Total Rows={$batch->total_rows}" );

            // Process batch
            $this->info( 'Processing batch...' );
            $result = $csvImportService->processBatch( $batch->id );

            $this->info( 'Import completed!' );
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Rows', $result['total_rows']],
                    ['Success', $result['success_count']],
                    ['Errors', $result['error_count']],
                    ['Status', $result['status']],
                ]
            );

            if ( $result['error_count'] > 0 ) {
                $this->warn( 'Some rows failed to import. Check batch errors for details.' );
            }

            return Command::SUCCESS;
        } catch ( \Exception $e ) {
            $this->error( 'Import failed: ' . $e->getMessage() );
            $this->error( $e->getTraceAsString() );

            return Command::FAILURE;
        }
    }
}
