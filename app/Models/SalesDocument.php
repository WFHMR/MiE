<?php

namespace App\Models;

class SalesDocument extends NsModel
{
    protected $table = 'nexopos_sales_documents';

    protected $guarded = [];

    protected $casts = ['lines' => 'array', 'customer_snapshot' => 'array', 'version' => 'integer'];
}
