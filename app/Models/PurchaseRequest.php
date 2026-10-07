<?php

namespace App\Models;

class PurchaseRequest extends NsModel
{
    protected $table = 'nexopos_purchase_requests';

    protected $guarded = [];

    protected $casts = ['lines' => 'array', 'version' => 'integer'];
}
