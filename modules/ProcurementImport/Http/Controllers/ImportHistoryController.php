<?php

namespace Modules\ProcurementImport\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\ProcurementImport\Models\CsvImportBatch;
use Illuminate\Http\Request;

/**
 * Import History Controller - ประวัติการ Import
 */
class ImportHistoryController extends Controller
{
    /**
     * Show import history
     */
    public function index(Request $request)
    {
        $branchId = session('current_branch_id', 1);
        
        $batches = CsvImportBatch::where('branch_id', $branchId)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(20);
        
        return view('ProcurementImport::import.history', compact('batches'));
    }
    
    /**
     * Show batch details
     */
    public function show($batchId)
    {
        $batch = CsvImportBatch::with(['rows', 'user'])->findOrFail($batchId);
        
        // Check branch access
        if ($batch->branch_id !== session('current_branch_id', 1)) {
            abort(403, 'Access denied to this branch data');
        }
        
        return view('ProcurementImport::import.batch-detail', compact('batch'));
    }
    
    /**
     * Delete batch (soft delete)
     */
    public function destroy($batchId)
    {
        $batch = CsvImportBatch::findOrFail($batchId);
        
        // Check branch access
        if ($batch->branch_id !== session('current_branch_id', 1)) {
            abort(403, 'Access denied to this branch data');
        }
        
        $batch->delete();
        
        return redirect()->route('procurement-import.history')
            ->with('success', 'ลบประวัติการนำเข้าเรียบร้อยแล้ว');
    }
    
    /**
     * Export batch as CSV
     */
    public function export($batchId)
    {
        $batch = CsvImportBatch::with('rows')->findOrFail($batchId);
        
        // Check branch access
        if ($batch->branch_id !== session('current_branch_id', 1)) {
            abort(403, 'Access denied to this branch data');
        }
        
        $filename = "import-batch-{$batchId}-" . date('Y-m-d-His') . ".csv";
        
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];
        
        $callback = function() use ($batch) {
            $file = fopen('php://output', 'w');
            
            // BOM for UTF-8
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Headers
            fputcsv($file, [
                'Row', 'SKU', 'Product', 'Status', 'Error Message'
            ]);
            
            // Data
            foreach ($batch->rows as $row) {
                fputcsv($file, [
                    $row->row_number,
                    $row->data['stock_code'] ?? '',
                    $row->data['product_name'] ?? '',
                    $row->status,
                    $row->error_message,
                ]);
            }
            
            fclose($file);
        };
        
        return response()->stream($callback, 200, $headers);
    }
}
