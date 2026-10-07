<?php

namespace Modules\ProcurementImport\Http\Controllers;

use App\Http\Controllers\DashboardController;
use App\Services\DateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\ProcurementImport\Models\CsvImportBatch;
use Modules\ProcurementImport\Services\CsvImportService;

class CsvImportController extends DashboardController
{
    protected CsvImportService $csvImportService;

    public function __construct(
        DateService $dateService,
        CsvImportService $csvImportService
    ) {
        parent::__construct($dateService);
        $this->csvImportService = $csvImportService;
    }

    /**
     * Show CSV import form
     */
    public function index()
    {
        return view('ProcurementImport::import.index');
    }

    /**
     * Handle CSV file upload
     */
    public function upload(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:10240', // 10MB max
        ]);

        try {
            // Store uploaded file temporarily
            $file = $request->file('csv_file');
            $filename = $file->getClientOriginalName();
            $path = $file->storeAs('csv-imports', time() . '_' . $filename, 'local');
            $fullPath = storage_path('app/' . $path);

            // Create batch from CSV
            $batch = $this->csvImportService->createBatchFromCsv(
                $fullPath,
                $filename,
                Auth::id(),
                Auth::user()->branch_id
            );

            return redirect()
                ->route('procurement-import.preview', $batch->id)
                ->with('success', __('CSV file uploaded successfully. Please review before processing.'));
        } catch (\Exception $e) {
            return back()
                ->with('error', __('Failed to upload CSV: ') . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Preview CSV data before processing
     */
    public function preview($batchId)
    {
        $batch = CsvImportBatch::with('rows')->findOrFail($batchId);

        // Get first 10 rows for preview
        $previewRows = $batch->rows()->limit(10)->get();

        return view('ProcurementImport::import.preview', compact('batch', 'previewRows'));
    }

    /**
     * Process CSV import batch
     */
    public function import($batchId)
    {
        $batch = CsvImportBatch::findOrFail($batchId);

        if ($batch->status !== 'pending') {
            return back()->with('error', __('This batch has already been processed.'));
        }

        try {
            // Process in background (or synchronously for now)
            $this->csvImportService->processBatch($batch);

            return redirect()
                ->route('procurement-import.result', $batch->id)
                ->with('success', __('CSV import completed successfully.'));
        } catch (\Exception $e) {
            return back()
                ->with('error', __('Import processing failed: ') . $e->getMessage());
        }
    }

    /**
     * Show import result
     */
    public function result($batchId)
    {
        $batch = CsvImportBatch::with(['rows' => function ($query) {
            $query->where('status', 'error');
        }])->findOrFail($batchId);

        return view('ProcurementImport::import.result', compact('batch'));
    }

    /**
     * Show import history
     */
    public function history()
    {
        $batches = CsvImportBatch::with('rows')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('ProcurementImport::import.history', compact('batches'));
    }

    /**
     * Delete import batch
     */
    public function destroy($batchId)
    {
        $batch = CsvImportBatch::findOrFail($batchId);
        $batch->delete();

        return back()->with('success', __('Import batch deleted successfully.'));
    }
}
