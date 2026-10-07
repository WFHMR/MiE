<?php

namespace App\Models;

class SalesQuotation extends NsModel
{
    protected $table = 'nexopos_sales_quotations';

    protected $guarded = [];

    protected $casts = ['pricing' => 'array', 'lines' => 'array', 'customer_snapshot' => 'array', 'version' => 'integer'];
}
