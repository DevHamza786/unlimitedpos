<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SquarePaymentsApiClient
{
    public function __construct(
        private string $accessToken,
        private string $environment = 'production'
    ) {}

    public function baseUrl(): string
    {
        $hosts = config('square.hosts', []);

        return rtrim((string) ($hosts[$this->environment] ?? $hosts['production']), '/');
    }

    /**
     * @return array{success: bool, message: string, locations?: array}
     */
    public function listLocations(): array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(25)
                ->get($this->baseUrl().'/v2/locations');

            if (! $response->successful()) {
                return $this->failResponse($response);
            }

            $json = $response->json();

            return [
                'success' => true,
                'message' => __('business.square_connection_ok'),
                'locations' => $json['locations'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::warning('Square API locations: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string, payments?: array, cursor?: ?string}
     */
    public function listPayments(string $locationId, ?string $beginTimeIso = null, ?string $cursor = null, int $limit = 50): array
    {
        $query = array_filter([
            'location_id' => $locationId,
            'begin_time' => $beginTimeIso,
            'cursor' => $cursor,
            'limit' => $limit,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(45)
                ->get($this->baseUrl().'/v2/payments', $query);

            if (! $response->successful()) {
                return $this->failResponse($response);
            }

            $json = $response->json();

            return [
                'success' => true,
                'message' => '',
                'payments' => $json['payments'] ?? [],
                'cursor' => $json['cursor'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Square API payments: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->accessToken,
            'Square-Version' => (string) config('square.api_version', '2024-02-15'),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @param  \Illuminate\Http\Client\Response  $response
     * @return array{success: bool, message: string}
     */
    private function failResponse($response): array
    {
        $body = $response->json();
        $msg = is_array($body) && ! empty($body['errors'][0]['detail'])
            ? (string) $body['errors'][0]['detail']
            : $response->body();

        return [
            'success' => false,
            'message' => substr(strip_tags($msg), 0, 400),
        ];
    }
}
