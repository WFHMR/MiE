<?php

namespace Modules\NexoHR\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OvertimeController extends Controller
{
    public function index()
    {
        return view('NexoHR::admin.overtimes.index');
    }

    public function create()
    {
        return view('NexoHR::admin.overtimes.create');
    }

    public function store(Request $request)
    {
        // TODO: Implement
        return redirect()->route('nexohr.admin.overtimes.index');
    }

    public function show($id)
    {
        return view('NexoHR::admin.overtimes.show');
    }

    public function edit($id)
    {
        return view('NexoHR::admin.overtimes.edit');
    }

    public function update(Request $request, $id)
    {
        // TODO: Implement
        return redirect()->route('nexohr.admin.overtimes.index');
    }

    public function destroy($id)
    {
        // TODO: Implement
        return redirect()->route('nexohr.admin.overtimes.index');
    }
}
