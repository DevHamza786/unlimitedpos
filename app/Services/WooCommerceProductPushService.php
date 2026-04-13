<?php

namespace App\Services;

use App\Business;
use App\Product;
use Illuminate\Support\Facades\Http;

class WooCommerceProductPushService
{
    public function businessIsConfigured(Business $business): bool
    {
        return $business->hasWooCommerceApiCredentials();
    }

    /**
     * Create or update a simple product on WooCommerce and store woocommerce_product_id on POS.
     *
     * @return array{success: bool, message: string, woocommerce_id?: int|null}
     */
    public function pushProduct(Business $business, Product $product): array
    {
        if (! $this->businessIsConfigured($business)) {
            return ['success' => false, 'message' => __('business.woocommerce_not_configured')];
        }

        if ($product->business_id != $business->id) {
            return ['success' => false, 'message' => __('messages.something_went_wrong')];
        }

        if ($product->woocommerce_disable_sync) {
            return ['success' => false, 'message' => __('business.woocommerce_sync_disabled_for_product')];
        }

        if ($product->type !== 'single') {
            return ['success' => false, 'message' => __('business.woocommerce_only_single_type')];
        }

        if ((int) $product->not_for_selling === 1) {
            return ['success' => false, 'message' => __('business.woocommerce_not_for_selling_skip')];
        }

        $variation = $product->variations()->whereNull('deleted_at')->orderBy('id')->first();
        if ($variation === null) {
            return ['success' => false, 'message' => __('business.woocommerce_no_variation')];
        }

        $qty = (float) $variation->variation_location_details()->sum('qty_available');
        $price = (float) $variation->sell_price_inc_tax;
        $sku = trim((string) ($variation->sub_sku ?: $product->sku)) ?: 'sku-'.$product->id;

        $payload = [
            'name' => $product->name,
            'type' => 'simple',
            'sku' => $sku,
            'regular_price' => number_format($price, 2, '.', ''),
            'manage_stock' => (bool) $product->enable_stock,
            'status' => 'publish',
        ];

        if ($product->enable_stock) {
            $payload['stock_quantity'] = max(0, (int) round($qty));
            $payload['stock_status'] = $payload['stock_quantity'] > 0 ? 'instock' : 'outofstock';
        } else {
            $payload['stock_status'] = 'instock';
        }

        $desc = trim((string) ($product->product_description ?? ''));
        if ($desc !== '') {
            $payload['description'] = $desc;
        }

        $imgUrl = $this->publicImageUrl($product);
        if ($imgUrl !== null) {
            $payload['images'] = [['src' => $imgUrl]];
        }

        $base = rtrim((string) $business->woocommerce_store_url, '/');
        $verify = (bool) config('constants.woocommerce_verify_ssl', true);
        $existingId = $product->woocommerce_product_id;

        try {
            $http = Http::withBasicAuth($business->woocommerce_consumer_key, $business->woocommerce_consumer_secret)
                ->withOptions(['verify' => $verify])
                ->acceptJson()
                ->asJson()
                ->timeout(90);

            if (! empty($existingId)) {
                $response = $http->put($base.'/wp-json/wc/v3/products/'.$existingId, $payload);
            } else {
                $response = $http->post($base.'/wp-json/wc/v3/products', $payload);
            }

            if ($response->successful()) {
                $data = $response->json();
                $id = isset($data['id']) ? (int) $data['id'] : null;
                if ($id) {
                    $product->woocommerce_product_id = $id;
                    $product->save();
                }

                return [
                    'success' => true,
                    'message' => __('business.woocommerce_product_synced'),
                    'woocommerce_id' => $id,
                ];
            }

            $body = $response->json();
            $msg = is_array($body) && ! empty($body['message']) ? $body['message'] : $response->body();

            return [
                'success' => false,
                'message' => __('business.woocommerce_push_failed').': '.substr(strip_tags((string) $msg), 0, 300),
            ];
        } catch (\Throwable $e) {
            \Log::warning('WooCommerce push product '.$product->id.': '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param  array<int>  $productIds
     * @return array{success: bool, message: string}
     */
    public function pushProducts(Business $business, array $productIds): array
    {
        $ok = 0;
        $fail = 0;
        $errors = [];

        foreach ($productIds as $pid) {
            $product = Product::where('business_id', $business->id)->find($pid);
            if ($product === null) {
                $fail++;
                continue;
            }
            $r = $this->pushProduct($business, $product);
            if ($r['success']) {
                $ok++;
            } else {
                $fail++;
                $errors[] = $product->name.': '.$r['message'];
            }
        }

        $msg = __('business.woocommerce_bulk_result', ['ok' => $ok, 'fail' => $fail]);
        if (! empty($errors)) {
            $msg .= ' '.implode(' | ', array_slice($errors, 0, 5));
        }

        return [
            'success' => $fail === 0,
            'message' => $msg,
        ];
    }

    private function publicImageUrl(Product $product): ?string
    {
        if (empty($product->image)) {
            return null;
        }
        $url = $product->image_url;
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return null;
        }
        $host = strtolower($host);
        if (in_array($host, ['127.0.0.1', 'localhost'], true)) {
            return null;
        }

        return $url;
    }
}
