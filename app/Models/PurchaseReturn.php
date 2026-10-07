<?php

namespace App\Models;

class PurchaseReturn extends NsModel
{
    protected $table = 'nexopos_purchase_returns';

    protected $guarded = [];

    protected $casts = ['lines' => 'array'];
}
