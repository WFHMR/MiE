<?php

namespace Modules\NexoHR\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

class SettingsController extends Controller
{
    public function index()
    {
        return view('NexoHR::admin.settings.index');
    }
}
