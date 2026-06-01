<?php

namespace App\Console\Commands;

use App\Business;
use App\Services\WooCommerceProductImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WooCommerceSyncProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pos:syncWoocommerceProducts {--full : Ignore last sync time and import/refresh all products}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-import new and updated products from WooCommerce into the POS';

    public function __construct(private WooCommerceProductImportService $importService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        ini_set('max_execution_time', '0');
        ini_set('memory_limit', '512M');

        // Prevent overlapping runs (cron may fire every 30s while a full
        // sync still runs for minutes). A simple file lock works on cPanel.
        $lockPath = storage_path('app/woocommerce_sync.lock');
        $lockHandle = fopen($lockPath, 'c');
        if ($lockHandle === false || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $this->info('Another WooCommerce sync is already running. Skipping.');

            return self::SUCCESS;
        }

        try {
            $this->runSync();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return self::SUCCESS;
    }

    private function runSync(): void
    {
        $full = (bool) $this->option('full');

        $businesses = Business::all();

        foreach ($businesses as $business) {
            if (! $business->hasWooCommerceApiCredentials()) {
                continue;
            }

            // Only fetch products changed since the last successful sync
            // (with a small overlap buffer to avoid clock-skew gaps).
            $modifiedAfter = null;
            if (! $full && ! empty($business->woocommerce_products_last_synced_at)) {
                $modifiedAfter = $business->woocommerce_products_last_synced_at->copy()->subMinutes(10);
            }

            $startedAt = now();

            try {
                $result = $this->importService->syncProducts($business, $modifiedAfter);

                // Advance the watermark only when the sync succeeded
                if (! empty($result['success'])) {
                    $business->woocommerce_products_last_synced_at = $startedAt;
                    $business->save();
                }

                $this->info("Business #{$business->id}: ".($result['message'] ?? ''));
            } catch (\Throwable $e) {
                Log::error('WooCommerce product sync failed for business '.$business->id.': '.$e->getMessage());
                $this->error("Business #{$business->id}: ".$e->getMessage());
            }
        }
    }
}
