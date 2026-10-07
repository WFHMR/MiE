<?php

namespace Modules\NexoHR\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

class QRCodeController extends Controller
{
    public function index()
    {
        return view('NexoHR::admin.qr-code.index');
    }
}
