<?php

namespace Database\Seeders;

use App\Business;
use App\BusinessLocation;
use App\Category;
use App\Contact;
use App\Product;
use App\Transaction;
use App\Unit;
use App\User;
use App\Utils\BusinessUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo products + one purchase (received, paid) + one sale (final, paid) for a single business.
 *
 * Configure via .env:
 *   DEMO_SEED_BUSINESS_ID=7
 *   DEMO_SEED_USER_ID=13   (uses that user's business_id)
 *
 * Safe to run once per business: skips if SKU DEMO-SEED-001 already exists for that business.
 */
class DemoProductPurchaseSellSeeder extends Seeder
{
    public function run(): void
    {
        $businessId = $this->resolveBusinessId();
        if (empty($businessId)) {
            $this->command->warn('Set DEMO_SEED_BUSINESS_ID or DEMO_SEED_USER_ID in .env to seed demo data.');

            return;
        }

        $business = Business::find($businessId);
        if ($business === null) {
            $this->command->error("Business id {$businessId} not found.");

            return;
        }

        if (Product::where('business_id', $businessId)->where('sku', 'DEMO-SEED-001')->exists()) {
            $this->command->info("Demo products already exist for business {$businessId}. Skipping.");

            return;
        }

        $location = BusinessLocation::where('business_id', $businessId)->orderBy('id')->first();
        if ($location === null) {
            $this->command->error("No business location for business {$businessId}.");

            return;
        }

        $ownerId = (int) $business->owner_id;
        $owner = User::find($ownerId);
        if ($owner === null) {
            $this->command->error("Business owner (user id {$ownerId}) not found.");

            return;
        }

        $unit = Unit::where('business_id', $businessId)->orderBy('id')->first();
        if ($unit === null) {
            $this->command->error("No unit found for business {$businessId} (expected default Pieces from registration).");

            return;
        }

        $walkIn = Contact::where('business_id', $businessId)
            ->where('type', 'customer')
            ->where('is_default', 1)
            ->first();
        if ($walkIn === null) {
            $walkIn = Contact::where('business_id', $businessId)->where('type', 'customer')->orderBy('id')->first();
        }
        if ($walkIn === null) {
            $this->command->error("No customer contact for business {$businessId}.");

            return;
        }

        $productUtil = new ProductUtil;
        $transactionUtil = new TransactionUtil;

        $currencyDetails = $transactionUtil->purchaseCurrencyDetails($businessId);
        $enableProductEditing = (int) ($business->enable_editing_product_from_purchase ?? 0);

        DB::beginTransaction();

        try {
            $this->primeSessionForCli($businessId);

            $category = Category::where('business_id', $businessId)
                ->where('category_type', 'product')
                ->where('name', 'Demo')
                ->where('parent_id', 0)
                ->first();
            if ($category === null) {
                $category = Category::create([
                    'business_id' => $businessId,
                    'name' => 'Demo',
                    'short_code' => null,
                    'parent_id' => 0,
                    'category_type' => 'product',
                    'created_by' => $ownerId,
                ]);
            }

            $refCount = $productUtil->setAndGetReferenceCount('contacts', $businessId);
            $supplierRef = $productUtil->generateReferenceNumber('contacts', $refCount, $businessId);
            $supplier = Contact::create([
                'business_id' => $businessId,
                'type' => 'supplier',
                'name' => 'Demo Supplier',
                'created_by' => $ownerId,
                'contact_id' => $supplierRef,
                'credit_limit' => 0,
            ]);

            $demoItems = [
                ['sku' => 'DEMO-SEED-001', 'name' => 'Demo Apple', 'purchase' => 50, 'sell' => 75, 'purchase_qty' => 100, 'sell_qty' => 12],
                ['sku' => 'DEMO-SEED-002', 'name' => 'Demo Bread', 'purchase' => 30, 'sell' => 45, 'purchase_qty' => 80, 'sell_qty' => 20],
                ['sku' => 'DEMO-SEED-003', 'name' => 'Demo Juice', 'purchase' => 40, 'sell' => 60, 'purchase_qty' => 60, 'sell_qty' => 8],
            ];

            $products = [];
            $variations = [];

            foreach ($demoItems as $row) {
                $product = Product::create([
                    'name' => $row['name'],
                    'business_id' => $businessId,
                    'type' => 'single',
                    'unit_id' => $unit->id,
                    'brand_id' => null,
                    'category_id' => $category->id,
                    'sub_category_id' => null,
                    'tax' => null,
                    'tax_type' => 'exclusive',
                    'enable_stock' => 1,
                    'alert_quantity' => 5,
                    'sku' => $row['sku'],
                    'barcode_type' => 'C128',
                    'created_by' => $ownerId,
                    'not_for_selling' => 0,
                ]);

                $product->product_locations()->sync([$location->id]);

                $pv = $product->product_variations()->create([
                    'name' => 'DUMMY',
                    'is_dummy' => 1,
                ]);
                $variation = $pv->variations()->create([
                    'name' => 'DUMMY',
                    'product_id' => $product->id,
                    'sub_sku' => $row['sku'],
                    'default_purchase_price' => $row['purchase'],
                    'dpp_inc_tax' => $row['purchase'],
                    'profit_percent' => 25,
                    'default_sell_price' => $row['sell'],
                    'sell_price_inc_tax' => $row['sell'],
                    'combo_variations' => [],
                ]);

                $products[] = $product;
                $variations[] = [
                    'product' => $product,
                    'variation' => $variation,
                    'purchase_qty' => $row['purchase_qty'],
                    'sell_qty' => $row['sell_qty'],
                    'purchase_price' => $row['purchase'],
                    'sell_price' => $row['sell'],
                ];
            }

            $purchaseLinesInput = [];
            $purchaseTotal = 0;
            foreach ($variations as $v) {
                $qty = $v['purchase_qty'];
                $pp = $v['purchase_price'];
                $lineTotal = $qty * $pp;
                $purchaseTotal += $lineTotal;
                $purchaseLinesInput[] = [
                    'product_id' => $v['product']->id,
                    'variation_id' => $v['variation']->id,
                    'quantity' => $qty,
                    'pp_without_discount' => $pp,
                    'discount_percent' => 0,
                    'purchase_price' => $pp,
                    'purchase_price_inc_tax' => $pp,
                    'item_tax' => 0,
                    'purchase_line_tax_id' => null,
                ];
            }

            $refCountPurchase = $productUtil->setAndGetReferenceCount('purchase', $businessId);
            $purchaseRef = $productUtil->generateReferenceNumber('purchase', $refCountPurchase, $businessId);

            $purchaseTxn = Transaction::create([
                'business_id' => $businessId,
                'location_id' => $location->id,
                'type' => 'purchase',
                'status' => 'received',
                'contact_id' => $supplier->id,
                'transaction_date' => Carbon::now()->subDays(3)->format('Y-m-d H:i:s'),
                'ref_no' => $purchaseRef,
                'total_before_tax' => $purchaseTotal,
                'tax_id' => null,
                'tax_amount' => 0,
                'discount_type' => null,
                'discount_amount' => 0,
                'shipping_charges' => 0,
                'final_total' => $purchaseTotal,
                'payment_status' => 'due',
                'created_by' => $ownerId,
                'exchange_rate' => 1,
            ]);

            $productUtil->createOrUpdatePurchaseLines($purchaseTxn, $purchaseLinesInput, $currencyDetails, $enableProductEditing);
            $productUtil->adjustStockOverSelling($purchaseTxn);

            $transactionUtil->createOrUpdatePaymentLines($purchaseTxn, [
                ['amount' => $purchaseTotal, 'method' => 'cash', 'is_return' => 0],
            ], $businessId, $ownerId);

            $transactionUtil->updatePaymentStatus($purchaseTxn->id, $purchaseTxn->final_total);

            $sellProductsInput = [];
            foreach ($variations as $v) {
                $sp = $v['sell_price'];
                $sq = $v['sell_qty'];
                $sellProductsInput[] = [
                    'product_id' => $v['product']->id,
                    'variation_id' => $v['variation']->id,
                    'quantity' => $sq,
                    'unit_price' => $sp,
                    'unit_price_inc_tax' => $sp,
                    'item_tax' => 0,
                    'tax_id' => null,
                    'enable_stock' => 1,
                    'product_type' => 'single',
                ];
            }

            $invoiceTotal = $productUtil->calculateInvoiceTotal($sellProductsInput, null, null, true);
            if ($invoiceTotal === false) {
                throw new \RuntimeException('Could not calculate invoice total.');
            }

            $sellInput = [
                'location_id' => $location->id,
                'status' => 'final',
                'contact_id' => $walkIn->id,
                'transaction_date' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
                'tax_rate_id' => null,
                'discount_type' => null,
                'discount_amount' => 0,
                'final_total' => $invoiceTotal['final_total'],
                'sale_note' => 'Demo sale (seeder)',
                'is_direct_sale' => 1,
                'commission_agent' => null,
                'exchange_rate' => 1,
            ];

            $sellTxn = $transactionUtil->createSellTransaction($businessId, $sellInput, $invoiceTotal, $ownerId, true);
            $transactionUtil->createOrUpdateSellLines($sellTxn, $sellProductsInput, $location->id);

            foreach ($sellProductsInput as $line) {
                $decreaseQty = $productUtil->num_uf($line['quantity']);
                $productUtil->decreaseProductQuantity(
                    $line['product_id'],
                    $line['variation_id'],
                    $location->id,
                    $decreaseQty
                );
            }

            $transactionUtil->createOrUpdatePaymentLines($sellTxn, [
                ['amount' => $sellTxn->final_total, 'method' => 'cash', 'is_return' => 0],
            ], $businessId, $ownerId);

            $transactionUtil->updatePaymentStatus($sellTxn->id, $sellTxn->final_total);

            $businessDetails = Business::find($businessId);
            $posSettings = empty($businessDetails->pos_settings)
                ? []
                : json_decode($businessDetails->pos_settings, true);
            $mapBusiness = [
                'id' => $businessId,
                'accounting_method' => $businessDetails->accounting_method,
                'location_id' => $location->id,
                'pos_settings' => $posSettings ?: [],
            ];
            $sellTxn->load('sell_lines');
            $transactionUtil->mapPurchaseSell($mapBusiness, $sellTxn->sell_lines, 'purchase');

            DB::commit();
            $this->command->info("Demo data created for business {$businessId}: purchase #{$purchaseTxn->id}, sale #{$sellTxn->id}.");
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function resolveBusinessId(): ?int
    {
        $bid = env('DEMO_SEED_BUSINESS_ID');
        if ($bid !== null && $bid !== '') {
            return (int) $bid;
        }

        $uid = env('DEMO_SEED_USER_ID');
        if ($uid === null || $uid === '') {
            return null;
        }

        $user = User::find((int) $uid);

        return $user !== null ? (int) $user->business_id : null;
    }

    /**
     * ProductUtil / TransactionUtil format numbers using session; CLI has no HTTP session.
     */
    private function primeSessionForCli(int $businessId): void
    {
        $bd = (new BusinessUtil)->getDetails($businessId);

        // Only `currency` here: Util::generateReferenceNumber checks session('business') and then
        // calls request()->session(), which breaks under `php artisan`. Prefixes load from DB via $business_id.
        session([
            'currency' => [
                'thousand_separator' => $bd->thousand_separator ?? ',',
                'decimal_separator' => $bd->decimal_separator ?? '.',
                'symbol' => $bd->currency_symbol ?? '',
            ],
        ]);
    }
}
