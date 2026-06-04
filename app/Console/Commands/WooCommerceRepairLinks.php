<?php

namespace App\Console\Commands;

use App\Business;
use App\Services\WooCommerceProductPushService;
use Illuminate\Console\Command;

class WooCommerceRepairLinks extends Command
{
    protected $signature = 'pos:woocommerceRepairLinks
                            {--business_id= : Business ID (defaults to first configured business)}
                            {--limit=25 : Max unlinked products to check per run (use 0 for all)}';

    protected $description = 'Link POS products to existing WooCommerce products by name/SKU/slug';

    public function __construct(private WooCommerceProductPushService $pushService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        @set_time_limit(0);

        $businessId = $this->option('business_id');
        $business = $businessId
            ? Business::find($businessId)
            : Business::whereNotNull('woocommerce_store_url')->first();

        if ($business === null || ! $business->hasWooCommerceApiCredentials()) {
            $this->error('No business with WooCommerce credentials found.');

            return self::FAILURE;
        }

        $limitOption = $this->option('limit');
        $limit = $limitOption === null || $limitOption === '' ? 25 : (int) $limitOption;
        if ($limit <= 0) {
            $limit = null;
        }

        $this->info('Repairing WooCommerce links for business #'.$business->id.' ('.$business->woocommerce_store_url.')');
        if ($limit !== null) {
            $this->comment('Batch size: '.$limit.' per run (increase with --limit=50 or --limit=0 for all).');
        } else {
            $this->warn('Checking ALL unlinked products — this can take a long time on shared hosting.');
        }

        $bar = null;

        $result = $this->pushService->repairMissingLinks(
            $business,
            $limit,
            function (int $current, int $total, $product) use (&$bar) {
                if ($bar === null) {
                    $this->newLine();
                    $this->info('Checking '.$total.' unlinked product(s)…');
                    $bar = $this->output->createProgressBar($total);
                    $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — #%id%');
                    $bar->setMessage((string) $product->id, 'id');
                    $bar->start();
                }

                $bar->setMessage((string) $product->id, 'id');
                $bar->advance();
            }
        );

        if ($bar !== null) {
            $bar->finish();
            $this->newLine();
        }

        $this->line($result['message']);

        if (($result['remaining'] ?? 0) > 0) {
            $this->comment('Run again: php artisan pos:woocommerceRepairLinks --limit='.($limit ?? 25));
        }

        return self::SUCCESS;
    }
}
