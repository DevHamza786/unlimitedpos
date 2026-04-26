<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Voucher;
use App\VoucherRedemption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoucherApiController extends Controller
{
    public function validateVoucher(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $data = $request->validate([
            'voucher_code' => 'required|string|max:64',
            'contact_id' => 'required|integer',
            'subtotal' => 'required|numeric|min:0',
            'tax' => 'required|numeric|min:0',
        ]);

        $voucher = Voucher::where('business_id', $business_id)
            ->where('code', trim($data['voucher_code']))
            ->first();

        if (empty($voucher) || ! $voucher->isRedeemableFor($business_id, (int) $data['contact_id'])) {
            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        $base_amount = (float) $data['subtotal'] + (float) $data['tax'];

        if (! empty($voucher->min_purchase_amount) && $base_amount < (float) $voucher->min_purchase_amount) {
            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        $discount_amount = ((float) $voucher->discount_percent / 100) * $base_amount;
        if (! empty($voucher->max_discount_amount)) {
            $discount_amount = min($discount_amount, (float) $voucher->max_discount_amount);
        }

        return [
            'success' => true,
            'data' => [
                'voucher_id' => $voucher->id,
                'voucher_code' => $voucher->code,
                'discount_percent' => (float) $voucher->discount_percent,
                'discount_amount' => (float) $discount_amount,
                'expires_at' => $voucher->expires_at,
            ],
        ];
    }

    public function redeemVoucher(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $data = $request->validate([
            'voucher_code' => 'required|string|max:64',
            'contact_id' => 'required|integer',
            'order_ref' => 'nullable|string|max:191',
            'transaction_id' => 'nullable|integer',
            'discount_amount' => 'required|numeric|min:0',
            'meta' => 'nullable|array',
        ]);

        $now = now();

        return DB::transaction(function () use ($business_id, $data, $now) {
            $voucher = Voucher::where('business_id', $business_id)
                ->where('code', trim($data['voucher_code']))
                ->lockForUpdate()
                ->first();

            if (empty($voucher)) {
                return ['success' => false, 'msg' => __('messages.not_found')];
            }

            if ($voucher->status === 'redeemed') {
                $existing = VoucherRedemption::where('voucher_id', $voucher->id)
                    ->when(! empty($data['order_ref']), fn($q) => $q->where('order_ref', $data['order_ref']))
                    ->when(! empty($data['transaction_id']), fn($q) => $q->where('transaction_id', $data['transaction_id']))
                    ->exists();

                return $existing
                    ? ['success' => true, 'msg' => __('messages.success')]
                    : ['success' => false, 'msg' => __('messages.something_went_wrong')];
            }

            if (! $voucher->isRedeemableFor($business_id, (int) $data['contact_id'])) {
                return ['success' => false, 'msg' => __('messages.something_went_wrong')];
            }

            $voucher->status = 'redeemed';
            $voucher->redeemed_at = $now;
            $voucher->redeemed_transaction_id = $data['transaction_id'] ?? null;
            $voucher->save();

            VoucherRedemption::create([
                'voucher_id' => $voucher->id,
                'business_id' => $business_id,
                'contact_id' => (int) $data['contact_id'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'order_ref' => $data['order_ref'] ?? null,
                'discount_amount' => (float) $data['discount_amount'],
                'redeemed_at' => $now,
                'meta_json' => $data['meta'] ?? null,
            ]);

            return ['success' => true, 'msg' => __('messages.success')];
        });
    }
}

