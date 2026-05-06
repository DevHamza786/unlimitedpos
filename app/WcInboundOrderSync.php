<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class WcInboundOrderSync extends Model
{
    protected $table = 'wc_inbound_order_syncs';

    protected $guarded = ['id'];

    protected $casts = [
        'items' => 'array',
        'payload' => 'array',
        'wc_created_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id');
    }
}
