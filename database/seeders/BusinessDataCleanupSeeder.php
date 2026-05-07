<?php

namespace Database\Seeders;

use App\Product;
use App\Transaction;
use App\TransactionPayment;
use App\TransactionSellLine;
use App\TransactionSellLinesPurchaseLines;
use App\PurchaseLine;
use App\ProductVariation;
use App\Variation;
use App\VariationLocationDetails;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DANGER: Deletes business operational data (products/stock/sell/purchase).
 * Use ONLY on the intended business_id after taking a backup.
 *
 * Run:
 * php artisan db:seed --class=Database\\Seeders\\BusinessDataCleanupSeeder --force
 */
class BusinessDataCleanupSeeder extends Seeder
{
    public function run(): void
    {
        $businessId = (int) env('CLEANUP_BUSINESS_ID', 0);
        if ($businessId <= 0) {
            throw new \RuntimeException('Set CLEANUP_BUSINESS_ID in .env (e.g. 7) before running this seeder.');
        }

        DB::transaction(function () use ($businessId) {
            // --- SELL/PURCHASE TRANSACTIONS ---
            // Delete sell_line_purchase_line pivot
            TransactionSellLinesPurchaseLines::whereIn(
                'sell_line_id',
                TransactionSellLine::whereIn(
                    'transaction_id',
                    Transaction::where('business_id', $businessId)->pluck('id')
                )->pluck('id')
            )->delete();

            // Delete sell lines
            TransactionSellLine::whereIn(
                'transaction_id',
                Transaction::where('business_id', $businessId)->pluck('id')
            )->delete();

            // Delete purchase lines
            PurchaseLine::whereIn(
                'transaction_id',
                Transaction::where('business_id', $businessId)->pluck('id')
            )->delete();

            // Delete payments
            TransactionPayment::whereIn(
                'transaction_id',
                Transaction::where('business_id', $businessId)->pluck('id')
            )->delete();

            // Delete transactions
            Transaction::where('business_id', $businessId)->delete();

            // --- PRODUCTS / STOCK ---
            $productIds = Product::where('business_id', $businessId)->pluck('id');

            // stock rows
            VariationLocationDetails::whereIn('product_id', $productIds)->delete();

            // variations + templates
            Variation::whereIn('product_id', $productIds)->delete();
            ProductVariation::whereIn('product_id', $productIds)->delete();

            // pivot product_locations
            DB::table('product_locations')->whereIn('product_id', $productIds)->delete();

            // products
            Product::where('business_id', $businessId)->delete();
        });
    }
}

