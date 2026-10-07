<?php

namespace Modules\ProcurementImport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Branch Selector Controller - เลือกสาขาสำหรับ Import
 */
class BranchSelectorController extends Controller
{
    /**
     * Get all branches
     */
    public function index()
    {
        // TODO: Replace with actual Branch model when available
        // For now, return hardcoded branches
        $branches = [
            ['id' => 1, 'name' => 'สาขาหลัก', 'code' => 'HQ'],
            ['id' => 2, 'name' => 'สาขา 2', 'code' => 'BR02'],
            ['id' => 3, 'name' => 'สาขา 3', 'code' => 'BR03'],
        ];
        
        return response()->json($branches);
    }
    
    /**
     * Set current branch in session
     */
    public function setCurrent(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|integer|min:1',
        ]);
        
        $branchId = $request->input('branch_id');
        
        // Store in session
        session(['current_branch_id' => $branchId]);
        
        return response()->json([
            'success' => true,
            'branch_id' => $branchId,
            'message' => 'เลือกสาขาเรียบร้อยแล้ว',
        ]);
    }
    
    /**
     * Get current selected branch
     */
    public function getCurrent()
    {
        $branchId = session('current_branch_id', 1);
        
        // TODO: Fetch branch details from database
        $branches = [
            1 => ['id' => 1, 'name' => 'สาขาหลัก', 'code' => 'HQ'],
            2 => ['id' => 2, 'name' => 'สาขา 2', 'code' => 'BR02'],
            3 => ['id' => 3, 'name' => 'สาขา 3', 'code' => 'BR03'],
        ];
        
        $branch = $branches[$branchId] ?? $branches[1];
        
        return response()->json($branch);
    }
    
    /**
     * Clear branch selection
     */
    public function clear()
    {
        session()->forget('current_branch_id');
        
        return response()->json([
            'success' => true,
            'message' => 'ล้างการเลือกสาขาเรียบร้อยแล้ว',
        ]);
    }
}
