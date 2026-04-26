<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class VoucherRedemption extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'redeemed_at' => 'datetime',
        'meta_json' => 'array',
    ];

    public function voucher()
    {
        return $this->belongsTo(\App\Voucher::class);
    }

    public function contact()
    {
        return $this->belongsTo(\App\Contact::class);
    }

    public function transaction()
    {
        return $this->belongsTo(\App\Transaction::class);
    }
}

