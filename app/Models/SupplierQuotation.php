<?php

namespace App\Models;

class SupplierQuotation extends NsModel
{
    protected $table = 'nexopos_supplier_quotations';

    protected $guarded = [];

    protected $casts = ['lines' => 'array'];
}
