<?php

namespace App\Models;

class SalesDelivery extends NsModel
{
    protected $table = 'nexopos_sales_deliveries';

    protected $guarded = [];

    protected $casts = ['lines' => 'array', 'customer_snapshot' => 'array', 'version' => 'integer'];
}
