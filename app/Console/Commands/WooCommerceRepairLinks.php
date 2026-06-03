<?php

namespace App\Console\Commands;

use App\Business;
use App\Services\WooCommerceProductPushService;
use Illuminate\Console\Command;

class WooCommerceRepairLinks extends Command
{
    protected $signature = 'pos:woocommerceRepairLinks {--business_id= : Business ID (defaults to first configured business)}';

    protected $description = 'Link POS products to existing WooCommerce products by name/SKU/slug';

    public function __construct(private WooCommerceProductPushService $pushService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $businessId = $this->option('business_id');
        $business = $businessId
            ? Business::find($businessId)
            : Business::whereNotNull('woocommerce_store_url')->first();

        if ($business === null || ! $business->hasWooCommerceApiCredentials()) {
            $this->error('No business with WooCommerce credentials found.');

            return self::FAILURE;
        }

        $this->info('Repairing WooCommerce links for business #'.$business->id.' ('.$business->woocommerce_store_url.')');

        $result = $this->pushService->repairMissingLinks($business);
        $this->line($result['message']);

        return self::SUCCESS;
    }
}
