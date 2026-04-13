<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'business';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = ['woocommerce_consumer_key', 'woocommerce_consumer_secret', 'woocommerce_webhook_secret'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'ref_no_prefixes' => 'array',
        'enabled_modules' => 'array',
        'email_settings' => 'array',
        'sms_settings' => 'array',
        'common_settings' => 'array',
        'weighing_scale_setting' => 'array',
        'woocommerce_enabled' => 'boolean',
        'woocommerce_import_orders_enabled' => 'boolean',
        'woocommerce_consumer_key' => 'encrypted',
        'woocommerce_consumer_secret' => 'encrypted',
        'woocommerce_webhook_secret' => 'encrypted',
    ];

    /**
     * Returns the date formats
     */
    public static function date_formats()
    {
        return [
            'd-m-Y' => 'dd-mm-yyyy',
            'm-d-Y' => 'mm-dd-yyyy',
            'd/m/Y' => 'dd/mm/yyyy',
            'm/d/Y' => 'mm/dd/yyyy',
        ];
    }

    /**
     * Get the owner details
     */
    public function owner()
    {
        return $this->hasOne(\App\User::class, 'id', 'owner_id');
    }

    /**
     * Get the Business currency.
     */
    public function currency()
    {
        return $this->belongsTo(\App\Currency::class);
    }

    /**
     * Get the Business currency.
     */
    public function locations()
    {
        return $this->hasMany(\App\BusinessLocation::class);
    }

    /**
     * Get the Business printers.
     */
    public function printers()
    {
        return $this->hasMany(\App\Printer::class);
    }

    /**
     * Get the Business subscriptions.
     */
    public function subscriptions()
    {
        return $this->hasMany('\Modules\Superadmin\Entities\Subscription');
    }

    /**
     * Creates a new business based on the input provided.
     *
     * @return object
     */
    public static function create_business($details)
    {
        $business = Business::create($details);

        return $business;
    }

    /**
     * Updates a business based on the input provided.
     *
     * @param  int  $business_id
     * @param  array  $details
     * @return object
     */
    public static function update_business($business_id, $details)
    {
        if (! empty($details)) {
            Business::where('id', $business_id)
                ->update($details);
        }
    }

    public function getBusinessAddressAttribute()
    {
        $location = $this->locations->first();
        $address = $location->landmark.', '.$location->city.
        ', '.$location->state.'<br>'.$location->country.', '.$location->zip_code;

        return $address;
    }

    /**
     * Store URL + REST keys saved (used to show Products → WooCommerce actions; push uses this too).
     */
    public function hasWooCommerceApiCredentials(): bool
    {
        if (empty(trim((string) $this->woocommerce_store_url))) {
            return false;
        }

        return ! empty($this->woocommerce_consumer_key) && ! empty($this->woocommerce_consumer_secret);
    }

    /**
     * User turned integration on in settings AND credentials exist.
     */
    public function isWooCommerceStoreConfigured(): bool
    {
        return (bool) $this->woocommerce_enabled && $this->hasWooCommerceApiCredentials();
    }
}
