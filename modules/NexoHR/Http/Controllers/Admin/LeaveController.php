<?php

namespace Modules\NexoHR\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function index()
    {
        return view('NexoHR::admin.leaves.index');
    }

    public function create()
    {
        return view('NexoHR::admin.leaves.create');
    }

    public function store(Request $request)
    {
        // TODO: Implement
        return redirect()->route('nexohr.admin.leaves.index');
    }

    public function show($id)
    {
        return view('NexoHR::admin.leaves.show');
    }

    public function edit($id)
    {
        return view('NexoHR::admin.leaves.edit');
    }

    public function update(Request $request, $id)
    {
        // TODO: Implement
        return redirect()->route('nexohr.admin.leaves.index');
    }

    public function destroy($id)
    {
        // TODO: Implement
        return redirect()->route('nexohr.admin.leaves.index');
    }
}
