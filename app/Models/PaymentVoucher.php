<?php

namespace App\Models;

class PaymentVoucher extends NsModel
{
    protected $table = 'nexopos_payment_vouchers';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'total' => 'decimal:5', 'confirmed_at' => 'datetime'];
    }

    public function getNumberAttribute(): string
    {
        return 'PV-' . $this->created_at->copy()->timezone( 'Asia/Bangkok' )->format( 'Ymd' ) . '-' . str_pad( (string) $this->id, 6, '0', STR_PAD_LEFT );
    }
}
