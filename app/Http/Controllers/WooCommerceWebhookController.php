<?php

namespace App\Http\Controllers;

use App\Business;
use App\Services\WooCommerceOrderImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WooCommerceWebhookController extends Controller
{
    public function order(Request $request, string $token, WooCommerceOrderImportService $importService)
    {
        $business = Business::where('woocommerce_webhook_token', $token)->first();
        if ($business === null) {
            abort(404);
        }

        if (! $business->woocommerce_import_orders_enabled) {
            return response()->json(['status' => 'disabled']);
        }

        if (! $business->hasWooCommerceApiCredentials()) {
            Log::warning('WooCommerce webhook: business missing API credentials', ['business_id' => $business->id]);

            return response()->json(['status' => 'error', 'reason' => 'not_configured'], 503);
        }

        $secret = $business->woocommerce_webhook_secret;
        if (! empty($secret)) {
            $header = (string) $request->header('X-WC-Webhook-Signature', '');
            if ($header === '') {
                Log::warning('WooCommerce webhook: missing X-WC-Webhook-Signature but POS has a secret configured', [
                    'business_id' => $business->id,
                ]);
                abort(401, 'Missing X-WC-Webhook-Signature');
            }
            if (! $this->signatureValid($request, (string) $secret)) {
                Log::warning('WooCommerce webhook: signature mismatch (same secret as in WooCommerce → Business settings, or clear POS secret)', [
                    'business_id' => $business->id,
                ]);
                abort(401, 'Invalid webhook signature');
            }
        }

        $order = $request->json()->all();
        if (! is_array($order) || empty($order['id'])) {
            return response()->json(['status' => 'ignored', 'reason' => 'invalid_body']);
        }

        try {
            $result = $importService->importOrderFromPayload($business, $order);

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('WooCommerce webhook import failed: '.$e->getMessage(), [
                'business_id' => $business->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function signatureValid(Request $request, string $secret): bool
    {
        $header = (string) $request->header('X-WC-Webhook-Signature', '');
        if ($header === '') {
            return false;
        }

        // Match WooCommerce: wp_specialchars_decode( $secret, ENT_QUOTES ) as HMAC key.
        $key = htmlspecialchars_decode($secret, ENT_QUOTES);

        $payload = $request->getContent();
        $expected = base64_encode(hash_hmac('sha256', $payload, $key, true));

        return hash_equals($expected, $header);
    }
}
