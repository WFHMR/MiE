<?php

namespace App\Models;

class StockCount extends NsModel
{
    protected $table = 'nexopos_stock_counts';

    protected $casts = ['lines' => 'array', 'version' => 'integer', 'confirmed_at' => 'datetime'];
}
