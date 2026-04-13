<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WooCommerceConnectionService
{
    /**
     * Ping WooCommerce REST API (read products,1 row).
     *
     * @return array{success: bool, message: string}
     */
    public function test(string $storeUrl, string $consumerKey, string $consumerSecret): array
    {
        $base = rtrim(trim($storeUrl), '/');
        if ($base === '') {
            return ['success' => false, 'message' => __('business.woocommerce_url_required')];
        }
        if ($consumerKey === '' || $consumerSecret === '') {
            return ['success' => false, 'message' => __('business.woocommerce_keys_required')];
        }

        $url = $base.'/wp-json/wc/v3/products';

        try {
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->withOptions(['verify' => (bool) config('constants.woocommerce_verify_ssl', true)])
                ->timeout(25)
                ->acceptJson()
                ->get($url, ['per_page' => 1]);

            if ($response->successful()) {
                return ['success' => true, 'message' => __('business.woocommerce_connection_ok')];
            }

            $body = $response->json();
            $msg = is_array($body) && ! empty($body['message']) ? $body['message'] : $response->body();

            return [
                'success' => false,
                'message' => __('business.woocommerce_connection_failed').': '.substr(strip_tags((string) $msg), 0, 240),
            ];
        } catch (\Throwable $e) {
            \Log::warning('WooCommerce connection test: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
