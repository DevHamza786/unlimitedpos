<?php

namespace App\Http\Controllers\Api;

use App\Business;
use App\Http\Controllers\Controller;
use App\WcInboundOrderSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WcOrderInboundController extends Controller
{
    /**
     * POST /api/wc-inbound/orders
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'min:1'],
            'order_id' => ['required', 'string', 'max:64'],
            'order_key' => ['nullable', 'string', 'max:64'],
            'transaction_id' => ['nullable', 'string', 'max:191'],
            'payment_status' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3'],
            'total_amount' => ['required', 'numeric'],
            'tax' => ['nullable', 'numeric'],
            'customer' => ['nullable', 'array'],
            'customer.name' => ['nullable', 'string', 'max:512'],
            'customer.email' => ['nullable', 'string', 'max:191'],
            'customer.phone' => ['nullable', 'string', 'max:64'],
            'items' => ['nullable', 'array'],
            'created_at' => ['nullable', 'string', 'max:64'],
        ]);

        if (! Business::where('id', $data['business_id'])->exists()) {
            throw ValidationException::withMessages([
                'business_id' => ['Invalid business_id.'],
            ]);
        }

        $wcCreated = null;
        if (! empty($data['created_at'])) {
            try {
                $wcCreated = \Carbon\Carbon::parse($data['created_at']);
            } catch (\Throwable) {
                $wcCreated = null;
            }
        }

        $customer = $data['customer'] ?? [];

        $record = WcInboundOrderSync::firstOrCreate(
            [
                'business_id' => $data['business_id'],
                'wc_order_id' => $data['order_id'],
            ],
            [
                'wc_order_key' => $data['order_key'] ?? null,
                'transaction_id' => $data['transaction_id'] ?? null,
                'payment_status' => $data['payment_status'],
                'currency' => strtoupper($data['currency']),
                'total_amount' => $data['total_amount'],
                'tax_total' => $data['tax'] ?? null,
                'customer_name' => $customer['name'] ?? null,
                'customer_email' => $customer['email'] ?? null,
                'customer_phone' => $customer['phone'] ?? null,
                'items' => $data['items'] ?? [],
                'payload' => array_merge($data, [
                    'received_at' => now()->toIso8601String(),
                    'ip' => $request->ip(),
                ]),
                'source' => 'woocommerce',
                'wc_created_at' => $wcCreated,
            ]
        );

        if (! $record->wasRecentlyCreated) {
            return response()->json([
                'success' => true,
                'message' => 'Order already synced.',
                'duplicate' => true,
                'id' => $record->id,
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order stored.',
            'duplicate' => false,
            'id' => $record->id,
        ], 201);
    }

    /**
     * Future: Square webhooks or other channels can post here with a type discriminator.
     */
    public function ping(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'wc-inbound ok',
            'hmac_required' => (bool) config('wc_inbound_sync.require_hmac'),
        ]);
    }
}
