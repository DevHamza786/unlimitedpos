<?php

namespace App\Services;

use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\Transaction;
use App\TransactionPayment;
use App\WcInboundOrderSync;
use Carbon\Carbon;

class SquarePaymentsImportService
{
    /**
     * Pull recent COMPLETED payments from Square and store in wc_inbound_order_syncs (source square_api).
     *
     * @return array{success: bool, imported: int, skipped: int, pages: int, message: string}
     */
    public function syncRecentPayments(Business $business, int $daysBack = 7, int $maxPages = 15, ?int $createdBy = null): array
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

        $createdBy = $createdBy ?? (int) ($business->owner_id ?? 0);
        if ($createdBy <= 0) {
            $createdBy = 1;
        }

        $location = $this->resolveLocation($business);
        $walkInCustomerId = $this->resolveWalkInCustomerId($business);

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

                // Always ensure a POS payment record exists (for reports),
                // even when the inbound sync row already exists.
                $this->upsertPosPaymentForReport(
                    business: $business,
                    location: $location,
                    contactId: $walkInCustomerId,
                    createdBy: $createdBy,
                    squarePaymentId: $paymentId,
                    amount: $total,
                    paidOn: $createdAt ?? now(),
                    currency: $currency
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

    private function resolveLocation(Business $business): BusinessLocation
    {
        $locId = (int) ($business->woocommerce_default_location_id ?? 0);
        if ($locId > 0) {
            $loc = BusinessLocation::where('business_id', $business->id)->where('id', $locId)->first();
            if ($loc) {
                return $loc;
            }
        }

        return BusinessLocation::where('business_id', $business->id)->orderBy('id')->firstOrFail();
    }

    private function resolveWalkInCustomerId(Business $business): int
    {
        $contact = Contact::where('business_id', $business->id)
            ->whereIn('type', ['customer', 'both'])
            ->where('is_default', 1)
            ->orderBy('id')
            ->first();

        if ($contact) {
            return (int) $contact->id;
        }

        // Fallback: any customer.
        $contact = Contact::where('business_id', $business->id)
            ->whereIn('type', ['customer', 'both'])
            ->orderBy('id')
            ->first();

        return $contact ? (int) $contact->id : 0;
    }

    /**
     * Create minimal POS records so built-in Sell Payment Report can filter by method=square.
     * This does NOT affect stock (no sell lines); it's purely for payment visibility/reporting.
     */
    private function upsertPosPaymentForReport(
        Business $business,
        BusinessLocation $location,
        int $contactId,
        int $createdBy,
        string $squarePaymentId,
        float $amount,
        \DateTimeInterface $paidOn,
        string $currency
    ): void {
        if ($contactId <= 0 || $amount <= 0) {
            return;
        }

        $ref = 'SQ-'.$squarePaymentId;

        $existing = TransactionPayment::where('business_id', $business->id)
            ->where('payment_ref_no', $ref)
            ->first();

        if ($existing) {
            return;
        }

        $tx = Transaction::create([
            'business_id' => $business->id,
            'location_id' => $location->id,
            'type' => 'sell',
            'status' => 'final',
            'payment_status' => 'paid',
            'contact_id' => $contactId,
            'invoice_no' => $ref,
            'ref_no' => $ref,
            'source' => 'square_api',
            'total_before_tax' => $amount,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'final_total' => $amount,
            'transaction_date' => Carbon::instance($paidOn)->toDateTimeString(),
            'created_by' => $createdBy,
            'additional_notes' => 'Imported from Square API',
            'is_created_from_api' => 1,
        ]);

        TransactionPayment::create([
            'transaction_id' => $tx->id,
            'business_id' => $business->id,
            'amount' => $amount,
            'method' => 'square',
            'paid_on' => Carbon::instance($paidOn)->toDateTimeString(),
            'created_by' => $createdBy,
            'payment_ref_no' => $ref,
            'transaction_no' => $squarePaymentId,
            'note' => 'Square API import',
        ]);
    }
}
