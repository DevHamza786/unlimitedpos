<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'redeemed_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(\App\Business::class);
    }

    public function contact()
    {
        return $this->belongsTo(\App\Contact::class);
    }

    public function redemptions()
    {
        return $this->hasMany(\App\VoucherRedemption::class);
    }

    public function isRedeemableFor($business_id, $contact_id): bool
    {
        if ((int) $this->business_id !== (int) $business_id) {
            return false;
        }

        if ((int) $this->contact_id !== (int) $contact_id) {
            return false;
        }

        if ($this->status !== 'active') {
            return false;
        }

        if (! empty($this->expires_at) && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}

