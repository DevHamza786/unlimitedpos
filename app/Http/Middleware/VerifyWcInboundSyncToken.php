<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWcInboundSyncToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('wc_inbound_sync.secret', '');
        if ($configured === '') {
            return response()->json([
                'success' => false,
                'message' => 'Inbound WooCommerce sync is not configured on the server.',
            ], 503);
        }

        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            $token = (string) $request->header('X-POS-API-Key', '');
        }

        if (! hash_equals($configured, (string) $token)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        if (config('wc_inbound_sync.require_hmac', false)) {
            $ts = $request->header('X-WC-Sync-Timestamp');
            $sig = (string) $request->header('X-WC-Sync-Signature', '');
            if ($ts === null || $sig === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'HMAC headers required.',
                ], 401);
            }

            $skew = (int) config('wc_inbound_sync.max_timestamp_skew_seconds', 300);
            if (abs(time() - (int) $ts) > $skew) {
                return response()->json([
                    'success' => false,
                    'message' => 'Request timestamp outside allowed window.',
                ], 401);
            }

            $body = $request->getContent();
            $expected = hash_hmac('sha256', $ts."\n".$body, $configured);
            if (! hash_equals($expected, $sig)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid signature.',
                ], 401);
            }
        }

        return $next($request);
    }
}
