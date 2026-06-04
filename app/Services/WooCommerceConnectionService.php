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
                if ($this->responseIsHtml($response) || ! is_array($response->json())) {
                    return ['success' => false, 'message' => __('business.woocommerce_api_html_response')];
                }

                $writeCheck = $this->testWritePermission($base, $consumerKey, $consumerSecret);
                if (! $writeCheck['success']) {
                    return $writeCheck;
                }

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

    /**
     * Probe create permission without saving a product (empty POST → validation error if allowed).
     *
     * @return array{success: bool, message: string}
     */
    private function testWritePermission(string $base, string $consumerKey, string $consumerSecret): array
    {
        $url = $base.'/wp-json/wc/v3/products';

        try {
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->withOptions(['verify' => (bool) config('constants.woocommerce_verify_ssl', true)])
                ->timeout(25)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'name' => 'POS API write test',
                    'type' => 'simple',
                    'status' => 'draft',
                ]);

            if ($this->responseIsHtml($response)) {
                return ['success' => false, 'message' => __('business.woocommerce_api_html_response')];
            }

            if ($this->isReadOnlyApiError($response)) {
                return ['success' => false, 'message' => __('business.woocommerce_read_only_keys')];
            }

            // 400 = validation failed but user may create; 201 = unexpected success with empty body.
            if ($response->status() === 400 || $response->successful()) {
                if ($response->successful()) {
                    $data = $response->json();
                    $productId = is_array($data) ? ($data['id'] ?? null) : null;
                    if ($productId) {
                        Http::withBasicAuth($consumerKey, $consumerSecret)
                            ->withOptions(['verify' => (bool) config('constants.woocommerce_verify_ssl', true)])
                            ->timeout(25)
                            ->delete($base.'/wp-json/wc/v3/products/'.$productId, ['force' => true]);
                    }
                }

                return ['success' => true, 'message' => ''];
            }

            $body = $response->json();
            $msg = is_array($body) && ! empty($body['message']) ? $body['message'] : $response->body();

            return [
                'success' => false,
                'message' => __('business.woocommerce_write_check_failed').': '.substr(strip_tags((string) $msg), 0, 200),
            ];
        } catch (\Throwable $e) {
            \Log::warning('WooCommerce write permission test: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function isReadOnlyApiError($response): bool
    {
        $body = $response->json();
        $message = strtolower(is_array($body) && ! empty($body['message']) ? (string) $body['message'] : (string) $response->body());
        $code = is_array($body) && ! empty($body['code']) ? (string) $body['code'] : '';

        return $code === 'rest_cannot_create'
            || str_contains($message, 'not allowed to create');
    }

    private function responseIsHtml($response): bool
    {
        $contentType = strtolower((string) $response->header('Content-Type'));
        if (str_contains($contentType, 'text/html')) {
            return true;
        }

        $body = ltrim((string) $response->body());
        if ($body === '') {
            return false;
        }

        return str_starts_with($body, '<!DOCTYPE') || str_starts_with($body, '<html');
    }
}
