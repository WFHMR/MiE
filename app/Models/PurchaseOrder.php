<?php

namespace App\Models;

class PurchaseOrder extends NsModel
{
    protected $table = 'nexopos_purchase_orders';

    protected $casts = ['lines' => 'array', 'receipts' => 'array', 'version' => 'integer'];
}
