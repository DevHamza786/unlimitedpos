<?php

namespace App\Console\Commands;

use App\Business;
use App\Product;
use App\Services\WooCommerceProductPushService;
use Illuminate\Console\Command;

class WooCommerceDiagnosePush extends Command
{
    protected $signature = 'pos:woocommerceDiagnosePush
                            {product_id : POS product ID}
                            {--business_id= : Business ID (defaults to first configured business)}
                            {--push : Attempt a real push after diagnosis}';

    protected $description = 'Show why a POS product may fail to push to WooCommerce (link lookup + optional push)';

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

        $product = Product::where('business_id', $business->id)
            ->where('id', (int) $this->argument('product_id'))
            ->first();

        if ($product === null) {
            $this->error('Product not found for business #'.$business->id);

            return self::FAILURE;
        }

        $this->info('Business #'.$business->id.' — '.$business->woocommerce_store_url);
        $this->line('POS product #'.$product->id.' — '.$product->name);
        $this->line('Type: '.$product->type.' | SKU: '.($product->sku ?: '(empty)').' | WC ID: '.($product->woocommerce_product_id ?: '(not linked)'));

        if ($product->woocommerce_product_id) {
            $this->comment('Already linked — push should UPDATE WooCommerce product '.$product->woocommerce_product_id);
        } else {
            $this->warn('Not linked — push will try to match an existing store product, then create if none found.');
            if ($this->pushService->tryRepairLinkForProduct($business, $product)) {
                $product->refresh();
                $this->info('Matched on store — linked to WooCommerce #'.$product->woocommerce_product_id);
            } else {
                $this->warn('No matching product found on WooCommerce (name/SKU/slug/variation SKU).');
            }
        }

        if ($this->option('push')) {
            $this->line('Pushing…');
            $result = $this->pushService->pushProduct($business, $product->fresh());
            if ($result['success']) {
                $this->info('Push OK: '.$result['message'].' (WC #'.($result['woocommerce_id'] ?? '?').')');

                return self::SUCCESS;
            }

            $this->error('Push failed: '.$result['message']);

            return self::FAILURE;
        }

        $this->comment('Run with --push to attempt a real sync after repair.');

        return self::SUCCESS;
    }
}
