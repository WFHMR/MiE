<?php

namespace Modules\NexoHR\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function index()
    {
        return view('NexoHR::admin.dashboard', [
            'title' => __('แดชบอร์ด HR'),
        ]);
    }
}
