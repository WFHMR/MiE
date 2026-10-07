<?php

namespace Modules\NexoHR\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OfficeLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OfficeLocationController extends Controller
{
    /**
     * Display a listing of office locations
     */
    public function index()
    {
        $officeLocations = OfficeLocation::orderBy('created_at', 'desc')->get();

        return view('NexoHR::admin.office-locations.index', compact('officeLocations'));
    }

    /**
     * Show the form for creating a new office location
     */
    public function create()
    {
        return view('NexoHR::admin.office-locations.create');
    }

    /**
     * Store a newly created office location
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius' => 'required|integer|min:1',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        OfficeLocation::create([
            'name' => $request->name,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'radius' => $request->radius,
            'is_active' => $request->has('is_active') ? 1 : 0,
            'description' => $request->description,
        ]);

        return redirect()->route('nexohr.admin.office-locations.index')
            ->with('success', 'เพิ่มพื้นที่สำนักงานสำเร็จ');
    }

    /**
     * Display the specified office location
     */
    public function show(OfficeLocation $officeLocation)
    {
        return view('NexoHR::admin.office-locations.show', compact('officeLocation'));
    }

    /**
     * Show the form for editing the specified office location
     */
    public function edit(OfficeLocation $officeLocation)
    {
        return view('NexoHR::admin.office-locations.edit', compact('officeLocation'));
    }

    /**
     * Update the specified office location
     */
    public function update(Request $request, OfficeLocation $officeLocation)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius' => 'required|integer|min:1',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $officeLocation->update([
            'name' => $request->name,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'radius' => $request->radius,
            'is_active' => $request->has('is_active') ? 1 : 0,
            'description' => $request->description,
        ]);

        return redirect()->route('nexohr.admin.office-locations.index')
            ->with('success', 'อัปเดตพื้นที่สำนักงานสำเร็จ');
    }

    /**
     * Remove the specified office location
     */
    public function destroy(OfficeLocation $officeLocation)
    {
        $officeLocation->delete();

        return redirect()->route('nexohr.admin.office-locations.index')
            ->with('success', 'ลบพื้นที่สำนักงานสำเร็จ');
    }
}
