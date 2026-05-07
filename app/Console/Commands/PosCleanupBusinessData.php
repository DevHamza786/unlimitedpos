<?php

namespace App\Console\Commands;

use App\Product;
use App\PurchaseLine;
use App\Transaction;
use App\TransactionPayment;
use App\TransactionSellLine;
use App\TransactionSellLinesPurchaseLines;
use App\ProductVariation;
use App\Variation;
use App\VariationLocationDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PosCleanupBusinessData extends Command
{
    protected $signature = 'pos:cleanup-business-data
        {business_id : Business ID to clean (e.g. 7)}
        {--dry-run : Only show counts}
        {--also-inbound : Also delete wc_inbound_order_syncs for this business}
        {--force : Required to actually delete}
    ';

    protected $description = 'Delete sells/purchases/products/stock for a business_id (production dangerous).';

    public function handle(): int
    {
        $businessId = (int) $this->argument('business_id');
        if ($businessId <= 0) {
            $this->error('Invalid business_id');
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $alsoInbound = (bool) $this->option('also-inbound');

        $txIds = Transaction::where('business_id', $businessId)->pluck('id');
        $productIds = Product::where('business_id', $businessId)->pluck('id');

        $counts = [
            'transactions' => $txIds->count(),
            'transaction_payments' => TransactionPayment::whereIn('transaction_id', $txIds)->count(),
            'sell_lines' => TransactionSellLine::whereIn('transaction_id', $txIds)->count(),
            'sell_line_purchase_line' => TransactionSellLinesPurchaseLines::whereIn(
                'sell_line_id',
                TransactionSellLine::whereIn('transaction_id', $txIds)->pluck('id')
            )->count(),
            'purchase_lines' => PurchaseLine::whereIn('transaction_id', $txIds)->count(),
            'products' => $productIds->count(),
            'variations' => Variation::whereIn('product_id', $productIds)->count(),
            'product_variations' => ProductVariation::whereIn('product_id', $productIds)->count(),
            'variation_location_details' => VariationLocationDetails::whereIn('product_id', $productIds)->count(),
            'product_locations' => DB::table('product_locations')->whereIn('product_id', $productIds)->count(),
        ];

        if ($alsoInbound) {
            $counts['wc_inbound_order_syncs'] = DB::table('wc_inbound_order_syncs')->where('business_id', $businessId)->count();
        }

        $this->info('Cleanup preview for business_id='.$businessId);
        foreach ($counts as $k => $v) {
            $this->line("- {$k}: {$v}");
        }

        if ($dry) {
            $this->warn('Dry-run only. No changes made.');
            return self::SUCCESS;
        }

        if (! $force) {
            $this->error('Refusing to delete without --force. Re-run with --force after backup.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($businessId, $txIds, $productIds, $alsoInbound) {
            TransactionSellLinesPurchaseLines::whereIn(
                'sell_line_id',
                TransactionSellLine::whereIn('transaction_id', $txIds)->pluck('id')
            )->delete();

            TransactionSellLine::whereIn('transaction_id', $txIds)->delete();
            PurchaseLine::whereIn('transaction_id', $txIds)->delete();
            TransactionPayment::whereIn('transaction_id', $txIds)->delete();
            Transaction::where('business_id', $businessId)->delete();

            VariationLocationDetails::whereIn('product_id', $productIds)->delete();
            Variation::whereIn('product_id', $productIds)->delete();
            ProductVariation::whereIn('product_id', $productIds)->delete();
            DB::table('product_locations')->whereIn('product_id', $productIds)->delete();
            Product::where('business_id', $businessId)->delete();

            if ($alsoInbound) {
                DB::table('wc_inbound_order_syncs')->where('business_id', $businessId)->delete();
            }
        });

        $this->info('Cleanup completed.');
        return self::SUCCESS;
    }
}

