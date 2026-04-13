<?php

namespace App\Services;

use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\Product;
use App\Transaction;
use App\Variation;
use App\Exceptions\PurchaseSellMismatch;
use App\Utils\BusinessUtil;
use App\Utils\ContactUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WooCommerceOrderImportService
{
    /**
     * Create a final sell from a WooCommerce order payload (REST / webhook body).
     *
     * @return array{status: string, order_id?: int, transaction_id?: int, reason?: string}
     */
    public function importOrderFromPayload(Business $business, array $order): array
    {
        $orderId = isset($order['id']) ? (int) $order['id'] : 0;
        if ($orderId <= 0) {
            return ['status' => 'ignored', 'reason' => 'no_order_id'];
        }

        if (Transaction::where('business_id', $business->id)->where('woocommerce_order_id', $orderId)->exists()) {
            return ['status' => 'duplicate', 'order_id' => $orderId];
        }

        $status = (string) ($order['status'] ?? '');
        if (! in_array($status, ['processing', 'completed', 'on-hold'], true)) {
            return ['status' => 'ignored', 'reason' => 'status_'.$status];
        }

        $locationId = $business->woocommerce_default_location_id
            ?: BusinessLocation::where('business_id', $business->id)->orderBy('id')->value('id');
        if (empty($locationId)) {
            return ['status' => 'error', 'reason' => 'no_location'];
        }

        $ownerId = (int) $business->owner_id;
        if ($ownerId <= 0) {
            return ['status' => 'error', 'reason' => 'no_owner'];
        }

        $this->primeSessionForWebhook($business);

        $sellProductsInput = [];
        foreach ($order['line_items'] ?? [] as $line) {
            if ((int) ($line['product_id'] ?? 0) === 0) {
                continue;
            }
            $qty = (float) ($line['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $resolved = $this->resolveProduct($business->id, $line);
            if ($resolved === null) {
                Log::info('WooCommerce order import: line skipped (no POS product)', [
                    'business_id' => $business->id,
                    'woocommerce_order_id' => $orderId,
                    'line_id' => $line['id'] ?? null,
                    'product_id' => $line['product_id'] ?? null,
                ]);

                continue;
            }

            [$product, $variation] = $resolved;
            if ($product->type !== 'single') {
                continue;
            }

            $lineTotal = (float) ($line['total'] ?? 0) + (float) ($line['total_tax'] ?? 0);
            $unitInc = $qty > 0 ? $lineTotal / $qty : (float) $variation->sell_price_inc_tax;

            $sellProductsInput[] = [
                'product_id' => $product->id,
                'variation_id' => $variation->id,
                'quantity' => $qty,
                'unit_price' => $unitInc,
                'unit_price_inc_tax' => $unitInc,
                'line_discount_type' => null,
                'line_discount_amount' => 0,
                'item_tax' => 0,
                'tax_id' => null,
                'sell_line_note' => '',
                'product_unit_id' => $product->unit_id,
                'enable_stock' => (int) $product->enable_stock,
                'type' => $product->type,
                'combo_variations' => [],
            ];
        }

        if ($sellProductsInput === []) {
            return ['status' => 'ignored', 'reason' => 'no_matching_lines'];
        }

        $productUtil = new ProductUtil;
        $transactionUtil = new TransactionUtil;

        $invoiceTotalCalc = $productUtil->calculateInvoiceTotal($sellProductsInput, null, null, true);
        if ($invoiceTotalCalc === false) {
            return ['status' => 'error', 'reason' => 'invoice_total'];
        }

        $linesPart = (float) $invoiceTotalCalc['final_total'];
        $shipping = (float) ($order['shipping_total'] ?? 0) + (float) ($order['shipping_tax'] ?? 0);
        $lineAndShip = $linesPart + $shipping;
        $wcGrand = (float) ($order['total'] ?? 0);
        if ($wcGrand <= 0) {
            $wcGrand = $lineAndShip;
        }
        $discountAmount = max(0, round($lineAndShip - $wcGrand, 4));

        $wcTax = (float) ($order['total_tax'] ?? 0);
        $invoiceTotal = [
            'total_before_tax' => max(0, $wcGrand - $wcTax),
            'tax' => $wcTax,
        ];

        $contact = $this->resolveContact($business, $order, $ownerId);
        $cg = (new ContactUtil)->getCustomerGroup($business->id, $contact->id);
        $customerGroupId = (empty($cg) || empty($cg->id)) ? null : $cg->id;

        $transactionDate = ! empty($order['date_created_gmt'])
            ? Carbon::parse($order['date_created_gmt'])->format('Y-m-d H:i:s')
            : Carbon::now()->format('Y-m-d H:i:s');

        $orderNumber = $order['number'] ?? (string) $orderId;
        $saleNote = 'WooCommerce #'.$orderNumber;
        if (! empty($order['payment_method_title'])) {
            $saleNote .= ' — '.$order['payment_method_title'];
        }

        $staffNote = null;
        if (abs($lineAndShip - $wcGrand) > 0.05 && $discountAmount === 0.0) {
            $staffNote = 'WC total '.number_format($wcGrand, 2).' vs lines+shipping '.number_format($lineAndShip, 2).' (fees/extra lines not mapped).';
        }

        try {
            DB::beginTransaction();

            $sellInput = [
                'location_id' => $locationId,
                'status' => 'final',
                'contact_id' => $contact->id,
                'customer_group_id' => $customerGroupId,
                'transaction_date' => $transactionDate,
                'tax_rate_id' => null,
                'discount_type' => $discountAmount > 0 ? 'fixed' : null,
                'discount_amount' => $discountAmount,
                'final_total' => $wcGrand,
                'sale_note' => $saleNote,
                'staff_note' => $staffNote,
                'is_direct_sale' => 1,
                'commission_agent' => null,
                'exchange_rate' => 1,
                'shipping_charges' => $shipping,
                'source' => 'woocommerce',
                'is_created_from_api' => 1,
            ];

            $sellTxn = $transactionUtil->createSellTransaction($business->id, $sellInput, $invoiceTotal, $ownerId, true);
            $transactionUtil->createOrUpdateSellLines($sellTxn, $sellProductsInput, $locationId, false, null, [], true);

            foreach ($sellProductsInput as $line) {
                if (! empty($line['enable_stock'])) {
                    $decreaseQty = $productUtil->num_uf($line['quantity']);
                    $productUtil->decreaseProductQuantity(
                        $line['product_id'],
                        $line['variation_id'],
                        $locationId,
                        $decreaseQty
                    );
                }
            }

            $transactionUtil->createOrUpdatePaymentLines($sellTxn, [
                [
                    'amount' => $sellTxn->final_total,
                    'method' => 'other',
                    'is_return' => 0,
                    'note' => 'WooCommerce '.$orderNumber,
                ],
            ], $business->id, $ownerId);

            $transactionUtil->updatePaymentStatus($sellTxn->id, $sellTxn->final_total);

            $businessDetails = Business::find($business->id);
            $posSettings = [];
            if (! empty($businessDetails->pos_settings)) {
                $decoded = json_decode($businessDetails->pos_settings, true);
                $posSettings = is_array($decoded) ? $decoded : [];
            }
            // mapPurchaseSell throws PurchaseSellMismatch when it cannot allocate sell qty to
            // purchase lines (FIFO/LIFO). That rolls back this whole transaction, undoing stock.
            // Web orders are not created from POS purchase batches — force overselling for mapping only.
            $mapBusiness = [
                'id' => $business->id,
                'accounting_method' => $businessDetails->accounting_method,
                'location_id' => $locationId,
                'pos_settings' => array_merge($posSettings, ['allow_overselling' => 1]),
            ];
            $sellTxn->load('sell_lines');
            $transactionUtil->mapPurchaseSell($mapBusiness, $sellTxn->sell_lines, 'purchase');

            $sellTxn->woocommerce_order_id = $orderId;
            $sellTxn->save();

            DB::commit();

            Log::info('WooCommerce order imported to POS', [
                'business_id' => $business->id,
                'woocommerce_order_id' => $orderId,
                'transaction_id' => $sellTxn->id,
            ]);

            return ['status' => 'imported', 'order_id' => $orderId, 'transaction_id' => $sellTxn->id];
        } catch (PurchaseSellMismatch $e) {
            DB::rollBack();
            Log::warning('WooCommerce order import: stock mapping failed', [
                'business_id' => $business->id,
                'woocommerce_order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'reason' => 'purchase_sell_mismatch', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * @return array{0: Product, 1: Variation}|null
     */
    private function resolveProduct(int $businessId, array $line): ?array
    {
        $wcProductId = (int) ($line['product_id'] ?? 0);
        $wcVariationId = (int) ($line['variation_id'] ?? 0);
        $sku = trim((string) ($line['sku'] ?? ''));

        $product = Product::where('business_id', $businessId)
            ->where('woocommerce_product_id', $wcProductId)
            ->first();

        if ($product !== null) {
            $variation = $product->variations()->whereNull('deleted_at')->orderBy('id')->first();
            if ($variation !== null) {
                return [$product, $variation];
            }

            return null;
        }

        if ($wcVariationId > 0) {
            $variation = Variation::where('woocommerce_variation_id', $wcVariationId)
                ->whereHas('product', function ($q) use ($businessId) {
                    $q->where('business_id', $businessId);
                })
                ->with('product')
                ->first();
            if ($variation !== null && $variation->product !== null) {
                return [$variation->product, $variation];
            }
        }

        if ($sku !== '') {
            $variation = Variation::where('sub_sku', $sku)
                ->whereHas('product', function ($q) use ($businessId) {
                    $q->where('business_id', $businessId);
                })
                ->with('product')
                ->first();
            if ($variation !== null && $variation->product !== null) {
                return [$variation->product, $variation];
            }
        }

        return null;
    }

    private function resolveContact(Business $business, array $order, int $ownerId): Contact
    {
        $billing = $order['billing'] ?? [];
        $email = trim((string) ($billing['email'] ?? ''));
        $phone = trim((string) ($billing['phone'] ?? ''));
        $firstName = trim((string) ($billing['first_name'] ?? ''));
        $lastName = trim((string) ($billing['last_name'] ?? ''));
        $company = trim((string) ($billing['company'] ?? ''));
        $name = trim($firstName.' '.$lastName);
        if ($name === '') {
            $name = $company !== '' ? $company : 'WooCommerce customer';
        }

        $q = Contact::where('business_id', $business->id)->where('type', 'customer');
        if ($email !== '') {
            $existing = (clone $q)->where('email', $email)->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        if ($phone !== '') {
            $existing = (clone $q)->where('mobile', $phone)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return Contact::create([
            'business_id' => $business->id,
            'type' => 'customer',
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'mobile' => $phone !== '' ? $phone : null,
            'created_by' => $ownerId,
            'contact_status' => 'active',
        ]);
    }

    private function primeSessionForWebhook(Business $business): void
    {
        $business->loadMissing('currency');
        $currency = $business->currency;
        if ($currency !== null) {
            $currencyData = [
                'id' => $currency->id,
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'thousand_separator' => $currency->thousand_separator,
                'decimal_separator' => $currency->decimal_separator,
            ];
        } else {
            $bd = (new BusinessUtil)->getDetails($business->id);
            $currencyData = [
                'thousand_separator' => $bd->thousand_separator ?? ',',
                'decimal_separator' => $bd->decimal_separator ?? '.',
                'symbol' => $bd->currency_symbol ?? '',
            ];
        }

        session([
            'user' => ['business_id' => $business->id],
            'business' => $business,
            'currency' => $currencyData,
        ]);
    }
}
