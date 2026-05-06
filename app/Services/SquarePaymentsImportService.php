<?php

namespace App\Services;

use App\Business;
use App\WcInboundOrderSync;
use Carbon\Carbon;

class SquarePaymentsImportService
{
    /**
     * Pull recent COMPLETED payments from Square and store in wc_inbound_order_syncs (source square_api).
     *
     * @return array{success: bool, imported: int, skipped: int, pages: int, message: string}
     */
    public function syncRecentPayments(Business $business, int $daysBack = 7, int $maxPages = 15): array
    {
        if (! $business->hasSquareApiCredentials()) {
            return [
                'success' => false,
                'imported' => 0,
                'skipped' => 0,
                'pages' => 0,
                'message' => __('business.square_not_configured'),
            ];
        }

        $token = $business->square_access_token;
        $locationId = trim((string) $business->square_location_id);
        $env = $business->square_environment === 'sandbox' ? 'sandbox' : 'production';

        $client = new SquarePaymentsApiClient($token, $env);
        $begin = Carbon::now()->subDays(max(1, min(90, $daysBack)))->toIso8601String();

        $imported = 0;
        $skipped = 0;
        $pages = 0;
        $cursor = null;

        do {
            $pages++;
            $batch = $client->listPayments($locationId, $begin, $cursor, 50);
            if (! $batch['success']) {
                return [
                    'success' => false,
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'pages' => $pages,
                    'message' => $batch['message'],
                ];
            }

            foreach ($batch['payments'] ?? [] as $payment) {
                if (! is_array($payment)) {
                    continue;
                }
                $status = strtoupper((string) ($payment['status'] ?? ''));
                if ($status !== 'COMPLETED') {
                    $skipped++;

                    continue;
                }

                $paymentId = (string) ($payment['id'] ?? '');
                if ($paymentId === '') {
                    continue;
                }

                $wcOrderKey = 'sq:'.$paymentId;
                if (strlen($wcOrderKey) > 64) {
                    $wcOrderKey = substr(hash('sha256', $paymentId), 0, 32);
                }

                $amountMoney = $payment['amount_money'] ?? [];
                $amountMinor = (int) ($amountMoney['amount'] ?? 0);
                $currency = strtoupper((string) ($amountMoney['currency'] ?? 'USD'));
                $total = round($amountMinor / 100, 4);

                $email = (string) ($payment['buyer_email_address'] ?? '');
                $name = '';
                if (! empty($payment['billing_address']) && is_array($payment['billing_address'])) {
                    $a = $payment['billing_address'];
                    $name = trim(implode(' ', array_filter([
                        $a['first_name'] ?? '',
                        $a['last_name'] ?? '',
                    ])));
                }

                $createdAt = null;
                if (! empty($payment['created_at'])) {
                    try {
                        $createdAt = Carbon::parse($payment['created_at']);
                    } catch (\Throwable) {
                        $createdAt = null;
                    }
                }

                $record = WcInboundOrderSync::firstOrCreate(
                    [
                        'business_id' => $business->id,
                        'wc_order_id' => $wcOrderKey,
                    ],
                    [
                        'wc_order_key' => null,
                        'transaction_id' => $paymentId,
                        'payment_status' => strtolower($status),
                        'currency' => strlen($currency) === 3 ? $currency : 'USD',
                        'total_amount' => $total,
                        'tax_total' => null,
                        'customer_name' => $name !== '' ? $name : null,
                        'customer_email' => $email !== '' ? $email : null,
                        'customer_phone' => null,
                        'items' => [],
                        'payload' => [
                            'square_payment' => $payment,
                            'imported_at' => now()->toIso8601String(),
                        ],
                        'source' => 'square_api',
                        'wc_created_at' => $createdAt,
                    ]
                );

                if ($record->wasRecentlyCreated) {
                    $imported++;
                } else {
                    $skipped++;
                }
            }

            $cursor = $batch['cursor'] ?? null;
        } while ($cursor !== null && $pages < $maxPages);

        $business->square_last_synced_at = now();
        $business->save();

        return [
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'pages' => $pages,
            'message' => __('business.square_sync_done', ['imported' => $imported, 'skipped' => $skipped]),
        ];
    }
}
