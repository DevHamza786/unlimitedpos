<?php

namespace App\Utils;

use App\Contact;
use App\Voucher;

class VoucherUtil extends Util
{
    /**
     * Calculate fixed discount amount for a voucher.
     * Base amount: subtotal + tax (excluding shipping).
     */
    public function calculateVoucherDiscountAmount(
        int $business_id,
        int $contact_id,
        string $voucher_code,
        array $products,
        $tax_rate_id
    ): array {
        $voucher = Voucher::where('business_id', $business_id)
            ->where('code', $voucher_code)
            ->first();

        if (empty($voucher)) {
            return ['success' => false, 'msg' => __('messages.not_found')];
        }

        if (! $voucher->isRedeemableFor($business_id, $contact_id)) {
            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        $contact = Contact::where('business_id', $business_id)->find($contact_id);
        if (empty($contact)) {
            return ['success' => false, 'msg' => __('messages.not_found')];
        }

        $invoice_total_no_discount = app(\App\Utils\ProductUtil::class)
            ->calculateInvoiceTotal($products, $tax_rate_id, null);

        if (empty($invoice_total_no_discount)) {
            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        $base_amount = (float) $invoice_total_no_discount['total_before_tax'] + (float) $invoice_total_no_discount['tax'];

        if (! empty($voucher->min_purchase_amount) && $base_amount < (float) $voucher->min_purchase_amount) {
            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        $discount_amount = ((float) $voucher->discount_percent / 100) * $base_amount;

        if (! empty($voucher->max_discount_amount)) {
            $discount_amount = min($discount_amount, (float) $voucher->max_discount_amount);
        }

        $discount_amount = max(0, $discount_amount);

        return [
            'success' => true,
            'voucher' => $voucher,
            'base_amount' => $base_amount,
            'discount_amount' => $discount_amount,
        ];
    }
}

