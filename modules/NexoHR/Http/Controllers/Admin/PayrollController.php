<?php

namespace Modules\NexoHR\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function index()
    {
        return view('NexoHR::admin.payrolls.index');
    }

    public function create()
    {
        return view('NexoHR::admin.payrolls.create');
    }

    public function store(Request $request)
    {
        // TODO: Implement
        return redirect()->route('nexohr.admin.payrolls.index');
    }

    public function show($id)
    {
        return view('NexoHR::admin.payrolls.show');
    }

    public function edit($id)
    {
        return view('NexoHR::admin.payrolls.edit');
    }

    public function update(Request $request, $id)
    {
        // TODO: Implement
        return redirect()->route('nexohr.admin.payrolls.index');
    }

    public function destroy($id)
    {
        // TODO: Implement
        return redirect()->route('nexohr.admin.payrolls.index');
    }
}
