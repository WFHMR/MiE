<?php

namespace Modules\NexoHR\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index()
    {
        return view('NexoHR::admin.attendances.index');
    }

    public function create()
    {
        return view('NexoHR::admin.attendances.create');
    }

    public function store(Request $request)
    {
        // TODO: Implement
        return redirect()->route('nexohr.admin.attendances.index');
    }

    public function show($id)
    {
        return view('NexoHR::admin.attendances.show');
    }

    public function edit($id)
    {
        return view('NexoHR::admin.attendances.edit');
    }

    public function update(Request $request, $id)
    {
        // TODO: Implement
        return redirect()->route('nexohr.admin.attendances.index');
    }

    public function destroy($id)
    {
        // TODO: Implement
        return redirect()->route('nexohr.admin.attendances.index');
    }
}
