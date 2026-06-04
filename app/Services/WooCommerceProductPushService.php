<?php

namespace App\Services;

use App\Business;
use App\BusinessLocation;
use App\Product;
use App\Utils\ProductUtil;
use App\Variation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WooCommerceProductPushService
{
    private const API_TIMEOUT = 60;

    public function businessIsConfigured(Business $business): bool
    {
        return $business->hasWooCommerceApiCredentials();
    }

    /**
     * Create or update a product on WooCommerce and store woocommerce_product_id on POS.
     *
     * @return array{success: bool, message: string, woocommerce_id?: int|null}
     */
    public function pushProduct(Business $business, Product $product, $http = null): array
    {
        $validation = $this->validateProductForPush($business, $product);
        if ($validation !== null) {
            return $validation;
        }

        $http = $http ?? $this->httpClient($business);

        if ($product->type === 'variable') {
            return $this->pushVariableProduct($business, $product, $http);
        }

        return $this->pushSimpleTypeProduct($business, $product, $http);
    }

    /**
     * Link a POS product to an existing WooCommerce product when possible.
     *
     * @return array{linked: int, not_found: int, message: string}
     */
    public function tryRepairLinkForProduct(Business $business, Product $product): bool
    {
        return $this->tryLinkToExistingWooProduct($business, $product);
    }

    public function repairMissingLinks(Business $business): array
    {
        if (! $this->businessIsConfigured($business)) {
            return ['linked' => 0, 'not_found' => 0, 'message' => __('business.woocommerce_not_configured')];
        }

        $linked = 0;
        $notFound = 0;

        $products = Product::where('business_id', $business->id)
            ->where(function ($query) {
                $query->whereNull('woocommerce_product_id')
                    ->orWhere('woocommerce_product_id', 0);
            })
            ->whereIn('type', ['single', 'variable', 'combo'])
            ->orderBy('id')
            ->get();

        foreach ($products as $product) {
            if ($this->tryLinkToExistingWooProduct($business, $product)) {
                $linked++;
            } else {
                $notFound++;
            }
        }

        return [
            'linked' => $linked,
            'not_found' => $notFound,
            'message' => __('business.woocommerce_repair_links_result', [
                'linked' => $linked,
                'not_found' => $notFound,
            ]),
        ];
    }

    private function tryLinkToExistingWooProduct(Business $business, Product $product): bool
    {
        if (! empty($product->woocommerce_product_id)) {
            return true;
        }

        try {
            $http = $this->httpClient($business);
            $payload = [
                'name' => $product->name,
                'sku' => trim((string) $product->sku),
                'type' => $product->type === 'variable' ? 'variable' : 'simple',
            ];

            $foundId = $this->findExistingWooProductId($http, $business, $product, $payload, false);
            if (empty($foundId)) {
                return false;
            }

            $product->woocommerce_product_id = $foundId;
            $product->save();
            Log::info('WooCommerce linked POS product '.$product->id.' to WooCommerce product '.$foundId);

            return true;
        } catch (\Throwable $e) {
            Log::warning('WooCommerce link POS product '.$product->id.': '.$e->getMessage());

            return false;
        }
    }

    /**
     * @param  array<int>  $productIds
     * @return array{success: bool, message: string}
     */
    public function pushProducts(Business $business, array $productIds): array
    {
        $ok = 0;
        $fail = 0;
        $errors = [];

        if ($productIds === []) {
            return [
                'success' => true,
                'message' => __('business.woocommerce_bulk_result', ['ok' => 0, 'fail' => 0]),
            ];
        }

        $http = $this->httpClient($business);

        $products = Product::where('business_id', $business->id)
            ->whereIn('id', $productIds)
            ->with([
                'variations' => function ($query) {
                    $query->whereNull('deleted_at')
                        ->with(['variation_location_details', 'product_variation']);
                },
                'product_variations',
            ])
            ->get()
            ->keyBy('id');

        foreach ($productIds as $pid) {
            $product = $products->get($pid);
            if ($product === null) {
                $fail++;

                continue;
            }
            $r = $this->pushProduct($business, $product, $http);
            if ($r['success']) {
                $ok++;
            } else {
                $fail++;
                $errors[] = $product->name.': '.$r['message'];
            }
        }

        $msg = __('business.woocommerce_bulk_result', ['ok' => $ok, 'fail' => $fail]);
        if (! empty($errors)) {
            $msg .= ' '.implode(' | ', array_slice($errors, 0, 5));
        }

        return [
            'success' => $fail === 0,
            'message' => $msg,
        ];
    }

    /**
     * @return array{success: bool, message: string, woocommerce_id?: int|null}|null
     */
    private function validateProductForPush(Business $business, Product $product): ?array
    {
        if (! $this->businessIsConfigured($business)) {
            return ['success' => false, 'message' => __('business.woocommerce_not_configured')];
        }

        if ($product->business_id != $business->id) {
            return ['success' => false, 'message' => __('messages.something_went_wrong')];
        }

        if ($product->woocommerce_disable_sync) {
            return ['success' => false, 'message' => __('business.woocommerce_sync_disabled_for_product')];
        }

        if (! in_array($product->type, ['single', 'variable', 'combo'], true)) {
            return ['success' => false, 'message' => __('business.woocommerce_push_type_not_supported')];
        }

        if ((int) $product->not_for_selling === 1) {
            return ['success' => false, 'message' => __('business.woocommerce_not_for_selling_skip')];
        }

        return null;
    }

    /**
     * Push a single or combo product to WooCommerce as a simple product.
     *
     * @return array{success: bool, message: string, woocommerce_id?: int|null}
     */
    private function pushSimpleTypeProduct(Business $business, Product $product, $http): array
    {
        $variation = $product->variations()->whereNull('deleted_at')->orderBy('id')->first();
        if ($variation === null) {
            return ['success' => false, 'message' => __('business.woocommerce_no_variation')];
        }

        $qty = $this->resolveProductStockQuantity($business, $product, $variation);
        $price = (float) $variation->sell_price_inc_tax;
        $sku = trim((string) ($variation->sub_sku ?: $product->sku)) ?: 'sku-'.$product->id;

        $payload = [
            'name' => $product->name,
            'type' => 'simple',
            'sku' => $sku,
            'regular_price' => number_format($price, 2, '.', ''),
            'status' => 'publish',
        ];

        $this->applyStockToPayload($payload, $qty);

        $desc = trim((string) ($product->product_description ?? ''));
        if ($desc !== '') {
            $payload['description'] = $desc;
        }

        $this->applyImagesToPayload($payload, $product);

        try {
            $saved = $this->saveParentProductToWoo($http, $business, $product, $payload);
            if (! $saved['success']) {
                return ['success' => false, 'message' => $saved['message']];
            }

            $id = $saved['woocommerce_id'];

            return [
                'success' => true,
                'message' => $saved['created']
                    ? __('business.woocommerce_product_created')
                    : __('business.woocommerce_product_synced'),
                'woocommerce_id' => $id,
            ];
        } catch (\Throwable $e) {
            Log::warning('WooCommerce push product '.$product->id.': '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string, woocommerce_id?: int|null}
     */
    private function pushVariableProduct(Business $business, Product $product, $http): array
    {
        $variations = $this->loadPushableVariations($product);
        if ($variations->isEmpty()) {
            return ['success' => false, 'message' => __('business.woocommerce_no_variation')];
        }

        $dimensionName = $this->resolveVariationDimensionName($product);
        $attributes = $this->buildWooParentAttributes($variations, $dimensionName);

        $parentSku = trim((string) $product->sku) ?: 'sku-'.$product->id;

        $payload = [
            'name' => $product->name,
            'type' => 'variable',
            'sku' => $parentSku,
            'status' => 'publish',
            'attributes' => $attributes,
        ];

        $desc = trim((string) ($product->product_description ?? ''));
        if ($desc !== '') {
            $payload['description'] = $desc;
        }

        $this->applyImagesToPayload($payload, $product);
        $imgUrl = $payload['images'][0]['src'] ?? null;

        try {
            $saved = $this->saveParentProductToWoo($http, $business, $product, $payload);
            if (! $saved['success']) {
                return ['success' => false, 'message' => $saved['message']];
            }

            $wooProductId = $saved['woocommerce_id'];
            if (empty($wooProductId)) {
                return ['success' => false, 'message' => __('business.woocommerce_missing_product_id')];
            }

            // Ensure parent image is set on update (variable parent PUT may omit images otherwise).
            if ($imgUrl !== null && ! $saved['created']) {
                $http->put($this->apiBase($business).'/products/'.$wooProductId, [
                    'images' => [['src' => $imgUrl]],
                ]);
            }

            $synced = 0;
            $failed = 0;
            $firstError = null;

            $batchResult = $this->pushVariableVariationsBatch(
                $http,
                $business,
                $product,
                $wooProductId,
                $variations,
                $dimensionName,
                $attributes
            );

            $synced = $batchResult['synced'];
            $failed = $batchResult['failed'];
            $firstError = $batchResult['error'];

            if ($failed > 0) {
                return [
                    'success' => false,
                    'message' => __('business.woocommerce_variable_partial_sync', [
                        'synced' => $synced,
                        'failed' => $failed,
                        'error' => $firstError ?? '',
                    ]),
                    'woocommerce_id' => $wooProductId,
                ];
            }

            $message = $saved['created']
                ? __('business.woocommerce_product_created')
                : __('business.woocommerce_product_synced');

            return [
                'success' => true,
                'message' => __('business.woocommerce_variable_synced', ['count' => $synced]).' '.$message,
                'woocommerce_id' => $wooProductId,
            ];
        } catch (\Throwable $e) {
            Log::warning('WooCommerce push variable product '.$product->id.': '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create or update the WooCommerce parent product.
     * If the stored WooCommerce ID is invalid/deleted, clears links and creates a new product.
     * If not linked, tries to match an existing WooCommerce product by SKU first.
     *
     * @return array{success: bool, message: string, woocommerce_id?: int|null, created?: bool, relinked?: bool}
     */
    private function saveParentProductToWoo($http, Business $business, Product $product, array $payload): array
    {
        $existingId = $product->woocommerce_product_id ? (int) $product->woocommerce_product_id : null;
        $relinked = false;

        if (empty($existingId)) {
            $foundId = $this->findExistingWooProductId($http, $business, $product, $payload, false);
            if (empty($foundId)) {
                $foundId = $this->findExistingWooProductId($http, $business, $product, $payload, true);
            }
            if ($foundId) {
                $existingId = $foundId;
                $relinked = true;
            }
        }

        if (! empty($existingId)) {
            $putPayload = $this->prepareParentUpdatePayload($payload);
            $response = $http->put($this->apiBase($business).'/products/'.$existingId, $putPayload);

            if ($response->successful()) {
                $parsed = $this->parseEntityResponse($response);
                if (! $parsed['success']) {
                    return ['success' => false, 'message' => $parsed['message']];
                }

                $wooId = isset($parsed['data']['id']) ? (int) $parsed['data']['id'] : $existingId;
                $product->woocommerce_product_id = $wooId;
                $product->save();

                return [
                    'success' => true,
                    'message' => $relinked ? __('business.woocommerce_relinked_by_sku') : '',
                    'woocommerce_id' => $wooId,
                    'created' => false,
                    'relinked' => $relinked,
                ];
            }

            if ($this->isInvalidIdError($response)) {
                $this->clearWooCommerceLinks($product);
                $product->refresh();
                $existingId = null;
            } else {
                Log::warning('WooCommerce parent PUT failed for POS product '.$product->id.' woo='.$existingId.': '.$this->parseApiError($response));

                return ['success' => false, 'message' => $this->apiFailureMessage($response)];
            }
        }

        $response = $http->post($this->apiBase($business).'/products', $payload);
        if (! $response->successful()) {
            if ($this->isCreateDeniedError($response)) {
                $foundId = $this->findExistingWooProductId($http, $business, $product, $payload, true);
                if ($foundId) {
                    $putResponse = $http->put(
                        $this->apiBase($business).'/products/'.$foundId,
                        $this->prepareParentUpdatePayload($payload)
                    );
                    if ($putResponse->successful()) {
                        $parsed = $this->parseEntityResponse($putResponse);
                        if ($parsed['success']) {
                            $wooId = isset($parsed['data']['id']) ? (int) $parsed['data']['id'] : $foundId;
                            $product->woocommerce_product_id = $wooId;
                            $product->save();

                            return [
                                'success' => true,
                                'message' => __('business.woocommerce_relinked_by_sku'),
                                'woocommerce_id' => $wooId,
                                'created' => false,
                                'relinked' => true,
                            ];
                        }
                    }

                    Log::warning('WooCommerce create blocked; relink PUT failed for POS product '.$product->id.' woo='.$foundId.': '.$this->parseApiError($putResponse));

                    return ['success' => false, 'message' => $this->apiFailureMessage($putResponse)];
                }
            }

            Log::warning('WooCommerce parent POST failed for POS product '.$product->id.': '.$this->parseApiError($response));

            return ['success' => false, 'message' => $this->apiFailureMessage($response)];
        }

        $parsed = $this->parseEntityResponse($response);
        if (! $parsed['success']) {
            return ['success' => false, 'message' => $parsed['message']];
        }

        $wooId = isset($parsed['data']['id']) ? (int) $parsed['data']['id'] : null;
        if (empty($wooId)) {
            return ['success' => false, 'message' => __('business.woocommerce_missing_product_id')];
        }

        $product->woocommerce_product_id = $wooId;
        $product->save();

        return [
            'success' => true,
            'message' => '',
            'woocommerce_id' => $wooId,
            'created' => true,
            'relinked' => false,
        ];
    }

    private function clearWooCommerceLinks(Product $product): void
    {
        $product->woocommerce_product_id = null;
        $product->save();

        Variation::where('product_id', $product->id)
            ->whereNull('deleted_at')
            ->update(['woocommerce_variation_id' => null]);
    }

    /**
     * Match an existing WooCommerce parent product before attempting create.
     */
    private function findExistingWooProductId($http, Business $business, Product $product, array $payload, bool $aggressive = false): ?int
    {
        $sku = trim((string) ($payload['sku'] ?? $product->sku ?? ''));
        $name = trim((string) ($payload['name'] ?? $product->name ?? ''));

        if ($name !== '') {
            $foundId = $this->findWooProductIdBySlug($http, $business, $name);
            if ($foundId) {
                return $foundId;
            }
        }

        if ($sku !== '') {
            $foundId = $this->findWooProductIdBySku($http, $business, $sku);
            if ($foundId) {
                return $foundId;
            }

            $foundId = $this->findWooProductIdByVariationSku($http, $business, $sku);
            if ($foundId) {
                return $foundId;
            }
        }

        foreach ($this->collectSkusForWooLookup($product, $payload) as $extraSku) {
            if ($extraSku === '' || $extraSku === $sku) {
                continue;
            }

            $foundId = $this->findWooProductIdBySku($http, $business, $extraSku);
            if ($foundId) {
                return $foundId;
            }

            $foundId = $this->findWooProductIdByVariationSku($http, $business, $extraSku);
            if ($foundId) {
                return $foundId;
            }
        }

        foreach ($this->buildWooSearchTerms($name, $sku) as $term) {
            $foundId = $this->findWooProductIdBySearch($http, $business, $term, $name, $sku);
            if ($foundId) {
                return $foundId;
            }
        }

        if ($aggressive) {
            return $this->findWooProductIdByScan($http, $business, $name, $sku);
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function buildWooSearchTerms(string $name, string $sku): array
    {
        $terms = [];
        $name = trim($name);
        $sku = trim($sku);

        if ($name !== '') {
            $terms[] = $name;

            $withoutSuffix = preg_replace('/\s*-\s*[\w-]+$/', '', $name);
            if (is_string($withoutSuffix) && $withoutSuffix !== '' && $withoutSuffix !== $name) {
                $terms[] = $withoutSuffix;
            }

            $words = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($words) && count($words) >= 4) {
                $terms[] = implode(' ', array_slice($words, 0, 5));
                $terms[] = implode(' ', array_slice($words, 0, 3));
            }
        }

        if ($sku !== '') {
            $terms[] = $sku;
        }

        return array_slice(array_values(array_unique(array_filter($terms))), 0, 4);
    }

    private function findWooProductIdBySku($http, Business $business, string $sku): ?int
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $response = $http->get($this->apiBase($business).'/products', [
            'sku' => $sku,
            'per_page' => 1,
        ]);

        if (! $response->successful() || $this->responseIsHtml($response)) {
            return null;
        }

        $items = $response->json();
        if (! is_array($items) || empty($items[0]['id'])) {
            return null;
        }

        return (int) $items[0]['id'];
    }

    /**
     * Variable products often have an empty parent SKU on WooCommerce; match via variation SKU.
     */
    private function findWooProductIdByVariationSku($http, Business $business, string $sku): ?int
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $response = $http->get($this->apiBase($business).'/products', [
            'search' => $sku,
            'type' => 'variable',
            'per_page' => 50,
            'status' => 'any',
        ]);

        if (! $response->successful() || $this->responseIsHtml($response)) {
            return null;
        }

        $items = $response->json();
        if (! is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (! is_array($item) || empty($item['id']) || ($item['type'] ?? '') !== 'variable') {
                continue;
            }

            $parentId = (int) $item['id'];
            $varResponse = $http->get($this->apiBase($business).'/products/'.$parentId.'/variations', [
                'sku' => $sku,
                'per_page' => 1,
            ]);

            if ($varResponse->successful() && ! $this->responseIsHtml($varResponse)) {
                $vars = $varResponse->json();
                if (is_array($vars) && ! empty($vars[0]['id'])) {
                    return $parentId;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function collectSkusForWooLookup(Product $product, array $payload): array
    {
        $skus = [];
        $payloadSku = trim((string) ($payload['sku'] ?? ''));
        if ($payloadSku !== '') {
            $skus[] = $payloadSku;
        }

        $parentSku = trim((string) $product->sku);
        if ($parentSku !== '') {
            $skus[] = $parentSku;
        }

        if ($product->type === 'variable') {
            foreach ($this->loadPushableVariations($product) as $variation) {
                $sub = trim((string) ($variation->sub_sku ?: $product->sku));
                if ($sub !== '') {
                    $skus[] = $sub;
                }
            }
        } else {
            $variation = $product->variations()->whereNull('deleted_at')->orderBy('id')->first();
            if ($variation !== null) {
                $sub = trim((string) ($variation->sub_sku ?: $product->sku));
                if ($sub !== '') {
                    $skus[] = $sub;
                }
            }
        }

        return array_values(array_unique(array_filter($skus)));
    }

    private function findWooProductIdBySearch($http, Business $business, string $term, string $posName = '', string $sku = ''): ?int
    {
        $term = trim($term);
        if ($term === '') {
            return null;
        }

        $response = $http->get($this->apiBase($business).'/products', [
            'search' => $term,
            'per_page' => 100,
        ]);

        $needle = $posName !== '' ? $posName : $term;

        return $this->pickBestWooProductMatch($response, $needle, $sku);
    }

    /**
     * Existing variable products already have attributes on WooCommerce — do not overwrite on PUT.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function prepareParentUpdatePayload(array $payload): array
    {
        $updatePayload = $payload;
        if (($updatePayload['type'] ?? '') === 'variable') {
            unset($updatePayload['attributes']);
        }

        return $updatePayload;
    }

    private function findWooProductIdBySlug($http, Business $business, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $slugs = [];
        $withoutSuffix = preg_replace('/\s*-\s*[\w-]+$/', '', $name);
        if (is_string($withoutSuffix) && $withoutSuffix !== '') {
            $slugs[] = Str::slug($withoutSuffix);
        }
        $slugs[] = Str::slug($name);
        $slugs = array_values(array_unique(array_filter($slugs)));

        foreach ($slugs as $slug) {
            $response = $http->get($this->apiBase($business).'/products', [
                'slug' => $slug,
                'per_page' => 5,
            ]);

            if (! $response->successful() || $this->responseIsHtml($response)) {
                continue;
            }

            $items = $response->json();
            if (is_array($items) && ! empty($items[0]['id'])) {
                return (int) $items[0]['id'];
            }
        }

        return null;
    }

    private function findWooProductIdByScan($http, Business $business, string $posName, string $sku): ?int
    {
        $posName = trim($posName);
        if ($posName === '' && $sku === '') {
            return null;
        }

        $normalizedPosName = $this->normalizeProductNameForMatch($posName);
        $bestId = null;
        $bestScore = 0.0;

        $searchHint = trim(preg_replace('/\s*-\s*[\w-]+$/', '', $posName) ?: '');
        $shortSearch = $searchHint !== ''
            ? implode(' ', array_slice(preg_split('/\s+/', $searchHint, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 4))
            : '';

        foreach ([$shortSearch, ''] as $searchTerm) {
            $page = 1;

            do {
                $params = [
                    'per_page' => 100,
                    'page' => $page,
                    'status' => 'any',
                ];

                if ($searchTerm !== '') {
                    $params['search'] = $searchTerm;
                }

                $response = $http->get($this->apiBase($business).'/products', $params);
                if (! $response->successful() || $this->responseIsHtml($response)) {
                    break;
                }

                $items = $response->json();
                if (! is_array($items) || $items === []) {
                    break;
                }

                foreach ($items as $item) {
                    if (! is_array($item) || empty($item['id'])) {
                        continue;
                    }

                    $itemId = (int) $item['id'];
                    $itemName = (string) ($item['name'] ?? '');
                    $itemSku = trim((string) ($item['sku'] ?? ''));

                    if ($sku !== '' && ($itemSku === $sku || str_contains(strtolower($itemName), strtolower($sku)))) {
                        return $itemId;
                    }

                    if ($normalizedPosName === '') {
                        continue;
                    }

                    $normalizedItemName = $this->normalizeProductNameForMatch($itemName);
                    if ($normalizedItemName === $normalizedPosName) {
                        return $itemId;
                    }

                    similar_text($normalizedPosName, $normalizedItemName, $score);
                    if ($score > $bestScore && $score >= 82.0) {
                        $bestScore = $score;
                        $bestId = $itemId;
                    }
                }

                $page++;
            } while ($page <= 10 && count($items) === 100);

            if ($bestId !== null) {
                return $bestId;
            }
        }

        return $bestId;
    }

    private function normalizeProductNameForMatch(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\s*-\s*[\w-]+$/', '', $name) ?? $name;
        $name = str_replace(["'", '’', '`', '"'], '', $name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    private function pickBestWooProductMatch($response, string $needle, string $sku = ''): ?int
    {
        if (! $response->successful() || $this->responseIsHtml($response)) {
            return null;
        }

        $items = $response->json();
        if (! is_array($items)) {
            return null;
        }

        $normalizedNeedle = $this->normalizeProductNameForMatch($needle);
        $sku = trim(strtolower($sku));
        $bestId = null;
        $bestScore = 0.0;

        foreach ($items as $item) {
            if (! is_array($item) || empty($item['id'])) {
                continue;
            }

            $itemId = (int) $item['id'];
            $itemName = (string) ($item['name'] ?? '');
            $normalizedItemName = $this->normalizeProductNameForMatch($itemName);
            $itemSku = strtolower(trim((string) ($item['sku'] ?? '')));

            if ($normalizedNeedle !== '' && $normalizedItemName === $normalizedNeedle) {
                return $itemId;
            }

            if ($sku !== '' && ($itemSku === $sku || str_contains(strtolower($itemName), $sku))) {
                return $itemId;
            }

            if ($normalizedNeedle !== '' && $normalizedItemName !== '') {
                if (str_contains($normalizedNeedle, $normalizedItemName) || str_contains($normalizedItemName, $normalizedNeedle)) {
                    return $itemId;
                }

                similar_text($normalizedNeedle, $normalizedItemName, $score);
                if ($score > $bestScore && $score >= 82.0) {
                    $bestScore = $score;
                    $bestId = $itemId;
                }
            }
        }

        return $bestId;
    }

    private function isCreateDeniedError($response): bool
    {
        $body = $response->json();
        $message = strtolower(is_array($body) && ! empty($body['message']) ? (string) $body['message'] : (string) $response->body());
        $code = is_array($body) && ! empty($body['code']) ? (string) $body['code'] : '';

        return $code === 'rest_cannot_create'
            || str_contains($message, 'not allowed to create');
    }

    private function isInvalidIdError($response): bool
    {
        $msg = strtolower($this->parseApiError($response));

        return str_contains($msg, 'invalid id')
            || str_contains($msg, 'invalid product id')
            || str_contains($msg, 'invalid resource id');
    }

    /**
     * Sync all variations in one WooCommerce batch request (much faster than one-by-one).
     *
     * @return array{synced: int, failed: int, error: ?string}
     */
    private function pushVariableVariationsBatch(
        $http,
        Business $business,
        Product $product,
        int $wooProductId,
        Collection $variations,
        string $dimensionName,
        array $parentAttributes
    ): array {
        $lookup = $this->buildWooVariationLookupMaps($http, $business, $wooProductId);

        $toCreate = [];
        $toUpdate = [];
        $createVariations = [];
        $updateVariations = [];

        foreach ($variations as $variation) {
            $payload = $this->buildVariationPushPayload($business, $product, $variation, $parentAttributes, $dimensionName);
            $existingId = $this->resolveExistingWooVariationId($variation, $payload, $lookup);

            if ($existingId) {
                $updateVariations[] = $variation;
                $toUpdate[] = array_merge(['id' => $existingId], $payload);
            } else {
                $createVariations[] = $variation;
                $toCreate[] = $payload;
            }
        }

        if ($toCreate === [] && $toUpdate === []) {
            return ['synced' => 0, 'failed' => 0, 'error' => null];
        }

        $response = $http->post(
            $this->apiBase($business).'/products/'.$wooProductId.'/variations/batch',
            [
                'create' => $toCreate,
                'update' => $toUpdate,
            ]
        );

        if (! $response->successful() || $this->responseIsHtml($response)) {
            return [
                'synced' => 0,
                'failed' => $variations->count(),
                'error' => $this->apiFailureMessage($response),
            ];
        }

        $data = $response->json();
        if (! is_array($data)) {
            return [
                'synced' => 0,
                'failed' => $variations->count(),
                'error' => (string) __('business.woocommerce_invalid_api_response'),
            ];
        }

        $synced = 0;
        $failed = 0;
        $firstError = null;

        foreach ($data['update'] ?? [] as $index => $item) {
            $variation = $updateVariations[$index] ?? null;
            if ($variation === null) {
                continue;
            }

            if ($this->batchVariationItemSucceeded($item)) {
                $wooVarId = (int) $item['id'];
                $variation->woocommerce_variation_id = $wooVarId;
                $variation->save();
                $lookup['by_id'][$wooVarId] = $item;
                $synced++;
            } else {
                $failed++;
                $firstError = $firstError ?? $this->batchVariationItemError($item);
            }
        }

        foreach ($data['create'] ?? [] as $index => $item) {
            $variation = $createVariations[$index] ?? null;
            if ($variation === null) {
                continue;
            }

            if ($this->batchVariationItemSucceeded($item)) {
                $wooVarId = (int) $item['id'];
                $variation->woocommerce_variation_id = $wooVarId;
                $variation->save();
                $lookup['by_id'][$wooVarId] = $item;
                $synced++;
            } else {
                $failed++;
                $firstError = $firstError ?? $this->batchVariationItemError($item);
            }
        }

        return ['synced' => $synced, 'failed' => $failed, 'error' => $firstError];
    }

    /**
     * @return array{by_id: array<int, array<string, mixed>>, by_label: array<string, int>, by_sku: array<string, int>}
     */
    private function buildWooVariationLookupMaps($http, Business $business, int $wooProductId): array
    {
        $byId = [];
        $byLabel = [];
        $bySku = [];
        $page = 1;

        do {
            $response = $http->get($this->apiBase($business).'/products/'.$wooProductId.'/variations', [
                'per_page' => 100,
                'page' => $page,
            ]);

            if (! $response->successful() || $this->responseIsHtml($response)) {
                break;
            }

            $items = $response->json();
            if (! is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                if (! is_array($item) || empty($item['id'])) {
                    continue;
                }

                $id = (int) $item['id'];
                $byId[$id] = $item;

                $label = $this->formatWooVariationLabel($item);
                if ($label !== null) {
                    $byLabel[strtolower($label)] = $id;
                }

                $sku = trim((string) ($item['sku'] ?? ''));
                if ($sku !== '') {
                    $bySku[strtolower($sku)] = $id;
                }
            }

            $page++;
        } while ($page <= 10 && count($items) === 100);

        return ['by_id' => $byId, 'by_label' => $byLabel, 'by_sku' => $bySku];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{by_id: array<int, array<string, mixed>>, by_label: array<string, int>, by_sku: array<string, int>}  $lookup
     */
    private function resolveExistingWooVariationId(Variation $variation, array $payload, array $lookup): ?int
    {
        if (! empty($variation->woocommerce_variation_id)) {
            $id = (int) $variation->woocommerce_variation_id;
            if (isset($lookup['by_id'][$id])) {
                return $id;
            }
        }

        $sku = strtolower(trim((string) ($payload['sku'] ?? '')));
        if ($sku !== '' && isset($lookup['by_sku'][$sku])) {
            return $lookup['by_sku'][$sku];
        }

        $label = strtolower(trim((string) $variation->name));
        if ($label !== '' && isset($lookup['by_label'][$label])) {
            return $lookup['by_label'][$label];
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $parentAttributes
     * @return array<string, mixed>
     */
    private function buildVariationPushPayload(
        Business $business,
        Product $product,
        Variation $variation,
        array $parentAttributes,
        string $dimensionName
    ): array {
        $qty = $this->resolveProductStockQuantity($business, $product, $variation);
        $price = (float) $variation->sell_price_inc_tax;
        $sku = $this->resolveVariationSkuForPush($product, $variation);

        $payload = [
            'sku' => $sku,
            'regular_price' => number_format($price, 2, '.', ''),
            'attributes' => $this->buildWooVariationAttributes($parentAttributes, $dimensionName, (string) $variation->name),
        ];

        $this->applyStockToPayload($payload, $qty);

        return $payload;
    }

    private function batchVariationItemSucceeded(mixed $item): bool
    {
        return is_array($item) && ! empty($item['id']) && empty($item['error']);
    }

    private function batchVariationItemError(mixed $item): string
    {
        if (is_array($item) && ! empty($item['error']['message'])) {
            return substr(strip_tags((string) $item['error']['message']), 0, 200);
        }

        return (string) __('business.woocommerce_variation_push_failed');
    }

    /**
     * @return string|null Error message on failure, null on success
     */
    private function pushVariationToWoo(
        $http,
        Business $business,
        Product $product,
        Variation $variation,
        int $wooProductId,
        string $dimensionName,
        array $parentAttributes
    ): ?string {
        $qty = $this->resolveProductStockQuantity($business, $product, $variation);
        $price = (float) $variation->sell_price_inc_tax;
        $sku = $this->resolveVariationSkuForPush($product, $variation);

        $payload = [
            'sku' => $sku,
            'regular_price' => number_format($price, 2, '.', ''),
            'attributes' => $this->buildWooVariationAttributes($parentAttributes, $dimensionName, (string) $variation->name),
        ];

        $this->applyStockToPayload($payload, $qty);

        $existingWooVarId = $variation->woocommerce_variation_id ? (int) $variation->woocommerce_variation_id : null;
        $base = $this->apiBase($business).'/products/'.$wooProductId.'/variations';

        if (! empty($existingWooVarId)) {
            $response = $http->put($base.'/'.$existingWooVarId, $payload);

            if ($response->successful()) {
                return $this->persistVariationFromResponse($variation, $response);
            }

            if ($this->isInvalidIdError($response)) {
                $variation->woocommerce_variation_id = null;
                $variation->save();
                $existingWooVarId = null;
            } else {
                $message = $this->apiFailureMessage($response);
                Log::warning('WooCommerce push variation '.$variation->id.': '.$message);

                return $message;
            }
        }

        if (empty($existingWooVarId) && $sku !== '') {
            $foundVarId = $this->findWooVariationIdBySku($http, $business, $wooProductId, $sku);
            if ($foundVarId) {
                $response = $http->put($base.'/'.$foundVarId, $payload);
                if ($response->successful()) {
                    return $this->persistVariationFromResponse($variation, $response);
                }
            }
        }

        if (empty($existingWooVarId)) {
            $foundVarId = $this->findWooVariationIdByLabel(
                $http,
                $business,
                $wooProductId,
                (string) $variation->name
            );
            if ($foundVarId) {
                $response = $http->put($base.'/'.$foundVarId, $payload);
                if ($response->successful()) {
                    return $this->persistVariationFromResponse($variation, $response);
                }
            }
        }

        $response = $http->post($base, $payload);

        if (! $response->successful()) {
            $message = $this->apiFailureMessage($response);
            Log::warning('WooCommerce push variation '.$variation->id.': '.$message);

            return $message;
        }

        return $this->persistVariationFromResponse($variation, $response);
    }

    private function resolveVariationSkuForPush(Product $product, Variation $variation): string
    {
        $base = trim((string) ($variation->sub_sku ?: $product->sku)) ?: 'sku-'.$product->id;
        $label = Str::slug(str_replace('/', '-', trim((string) $variation->name)), '-');

        if ($label !== '') {
            return $base.'-'.$label;
        }

        return $base.'-'.$variation->id;
    }

    private function persistVariationFromResponse(Variation $variation, $response): ?string
    {
        $parsed = $this->parseEntityResponse($response);
        if (! $parsed['success']) {
            Log::warning('WooCommerce push variation '.$variation->id.': '.$parsed['message']);

            return $parsed['message'];
        }

        $data = $parsed['data'];
        $wooVarId = isset($data['id']) ? (int) $data['id'] : null;
        if ($wooVarId) {
            $variation->woocommerce_variation_id = $wooVarId;
            $variation->save();
        }

        return null;
    }

    private function findWooVariationIdBySku($http, Business $business, int $wooProductId, string $sku): ?int
    {
        $response = $http->get($this->apiBase($business).'/products/'.$wooProductId.'/variations', [
            'sku' => $sku,
            'per_page' => 1,
        ]);

        if (! $response->successful() || $this->responseIsHtml($response)) {
            return null;
        }

        $items = $response->json();
        if (! is_array($items) || empty($items[0]['id'])) {
            return null;
        }

        return (int) $items[0]['id'];
    }

    private function findWooVariationIdByLabel($http, Business $business, int $wooProductId, string $variationName): ?int
    {
        $variationName = trim($variationName);
        if ($variationName === '') {
            return null;
        }

        $page = 1;
        $target = strtolower($variationName);

        do {
            $response = $http->get($this->apiBase($business).'/products/'.$wooProductId.'/variations', [
                'per_page' => 100,
                'page' => $page,
            ]);

            if (! $response->successful() || $this->responseIsHtml($response)) {
                return null;
            }

            $items = $response->json();
            if (! is_array($items) || $items === []) {
                return null;
            }

            foreach ($items as $item) {
                if (! is_array($item) || empty($item['id'])) {
                    continue;
                }

                $label = $this->formatWooVariationLabel($item);
                if ($label !== null && strtolower($label) === $target) {
                    return (int) $item['id'];
                }
            }

            $page++;
        } while (count($items) === 100);

        return null;
    }

    private function formatWooVariationLabel(array $wooVariation): ?string
    {
        $attributes = $wooVariation['attributes'] ?? null;
        if (! is_array($attributes) || $attributes === []) {
            return null;
        }

        $parts = [];
        foreach ($attributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $option = trim((string) ($attribute['option'] ?? ''));
            if ($option !== '') {
                $parts[] = $option;
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode(' / ', $parts);
    }

    /**
     * @return Collection<int, Variation>
     */
    private function loadPushableVariations(Product $product): Collection
    {
        $variations = $product->variations()
            ->whereNull('deleted_at')
            ->whereHas('product_variation', function ($query) {
                $query->where('is_dummy', 0);
            })
            ->with('variation_location_details')
            ->orderBy('id')
            ->get();

        if ($variations->isNotEmpty()) {
            return $variations;
        }

        return $product->variations()
            ->whereNull('deleted_at')
            ->with('variation_location_details')
            ->orderBy('id')
            ->get()
            ->reject(function (Variation $variation) {
                return strtolower(trim((string) $variation->name)) === 'default';
            })
            ->values();
    }

    private function resolveVariationDimensionName(Product $product): string
    {
        $productVariation = $product->product_variations()->where('is_dummy', 0)->orderBy('id')->first();
        $name = trim((string) ($productVariation->name ?? ''));

        return $name !== '' ? $name : 'Variation';
    }

    /**
     * @param  Collection<int, Variation>  $variations
     * @return array<int, array<string, mixed>>
     */
    private function buildWooParentAttributes(Collection $variations, string $dimensionName): array
    {
        $dimensionParts = $this->splitVariationLabel($dimensionName);

        if (count($dimensionParts) > 1) {
            $attributes = [];
            foreach ($dimensionParts as $index => $partName) {
                $options = [];
                foreach ($variations as $variation) {
                    $valueParts = $this->splitVariationLabel((string) $variation->name);
                    if (isset($valueParts[$index]) && $valueParts[$index] !== '') {
                        $options[] = $valueParts[$index];
                    }
                }

                $attributes[] = [
                    'name' => $partName,
                    'variation' => true,
                    'visible' => true,
                    'options' => array_values(array_unique($options)),
                ];
            }

            if (! empty($attributes)) {
                return $attributes;
            }
        }

        return [[
            'name' => $dimensionName,
            'variation' => true,
            'visible' => true,
            'options' => $variations->pluck('name')->unique()->values()->all(),
        ]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $parentAttributes
     * @return array<int, array<string, string>>
     */
    private function buildWooVariationAttributes(array $parentAttributes, string $dimensionName, string $variationName): array
    {
        if (count($parentAttributes) > 1) {
            $valueParts = $this->splitVariationLabel($variationName);
            $attributes = [];

            foreach ($parentAttributes as $index => $parentAttribute) {
                if (! isset($valueParts[$index]) || $valueParts[$index] === '') {
                    continue;
                }

                $attributes[] = [
                    'name' => (string) ($parentAttribute['name'] ?? ''),
                    'option' => $valueParts[$index],
                ];
            }

            if (! empty($attributes)) {
                return $attributes;
            }
        }

        $attrName = (string) ($parentAttributes[0]['name'] ?? $dimensionName);

        return [[
            'name' => $attrName,
            'option' => $variationName,
        ]];
    }

    /**
     * @return array<int, string>
     */
    private function splitVariationLabel(string $label): array
    {
        $parts = array_map('trim', explode(' / ', $label));

        return array_values(array_filter($parts, fn ($part) => $part !== ''));
    }

    private function httpClient(Business $business)
    {
        $verify = (bool) config('constants.woocommerce_verify_ssl', true);

        return Http::withBasicAuth($business->woocommerce_consumer_key, $business->woocommerce_consumer_secret)
            ->withOptions(['verify' => $verify])
            ->acceptJson()
            ->asJson()
            ->timeout(self::API_TIMEOUT);
    }

    private function apiBase(Business $business): string
    {
        return rtrim((string) $business->woocommerce_store_url, '/').'/wp-json/wc/v3';
    }

    private function parseApiError($response): string
    {
        if ($this->responseIsHtml($response)) {
            return (string) __('business.woocommerce_api_html_response');
        }

        $body = $response->json();
        $msg = is_array($body) && ! empty($body['message']) ? $body['message'] : $response->body();
        $msgLower = strtolower((string) $msg);

        if ((is_array($body) && ($body['code'] ?? '') === 'rest_cannot_create')
            || str_contains($msgLower, 'not allowed to create')) {
            return (string) __('business.woocommerce_create_denied');
        }

        return substr(strip_tags((string) $msg), 0, 300);
    }

    private function extractRawApiMessage($response): string
    {
        if ($this->responseIsHtml($response)) {
            return '';
        }

        $body = $response->json();
        if (! is_array($body)) {
            return substr(strip_tags((string) $response->body()), 0, 300);
        }

        $msg = trim((string) ($body['message'] ?? ''));
        $code = trim((string) ($body['code'] ?? ''));

        if ($msg === '' && $code === '') {
            return '';
        }

        if ($code !== '' && $msg !== '') {
            return $code.': '.$msg;
        }

        return $code !== '' ? $code : $msg;
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    private function parseEntityResponse($response): array
    {
        if ($this->responseIsHtml($response)) {
            return ['success' => false, 'message' => __('business.woocommerce_api_html_response')];
        }

        $data = $response->json();
        if (! is_array($data)) {
            return ['success' => false, 'message' => __('business.woocommerce_invalid_api_response')];
        }

        return ['success' => true, 'message' => '', 'data' => $data];
    }

    private function apiFailureMessage($response): string
    {
        $error = $this->parseApiError($response);
        $raw = $this->extractRawApiMessage($response);

        if ($error === (string) __('business.woocommerce_create_denied')) {
            if ($raw !== '' && ! str_contains(strtolower($error), strtolower($raw))) {
                return $error.' ['.$raw.']';
            }

            return $error;
        }

        if ($error === (string) __('business.woocommerce_api_html_response')) {
            return $error;
        }

        return __('business.woocommerce_push_failed').': '.$error;
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

    private function publicImageUrl(Product $product): ?string
    {
        if (empty($product->image)) {
            return null;
        }

        if (filter_var($product->image, FILTER_VALIDATE_URL)) {
            return (string) $product->image;
        }

        $baseUrl = rtrim(
            (string) (config('constants.woocommerce_public_app_url') ?: config('app.url')),
            '/'
        );
        if ($baseUrl === '') {
            return null;
        }

        $url = $baseUrl.'/uploads/img/'.rawurlencode($product->image);
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return null;
        }

        $host = strtolower($host);
        if (in_array($host, ['127.0.0.1', 'localhost'], true)) {
            return null;
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyStockToPayload(array &$payload, int $qty): void
    {
        $payload['manage_stock'] = true;
        $payload['stock_quantity'] = max(0, $qty);
        $payload['stock_status'] = $payload['stock_quantity'] > 0 ? 'instock' : 'outofstock';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyImagesToPayload(array &$payload, Product $product): void
    {
        $imgUrl = $this->publicImageUrl($product);
        if ($imgUrl !== null) {
            $payload['images'] = [['src' => $imgUrl]];
        }
    }

    private function resolveProductStockQuantity(Business $business, Product $product, Variation $variation): int
    {
        if ($product->type === 'combo') {
            return $this->resolveComboStockQuantity($business, $variation);
        }

        return max(0, (int) round((float) $variation->variation_location_details()->sum('qty_available')));
    }

    private function resolveComboStockQuantity(Business $business, Variation $variation): int
    {
        $comboVariations = $variation->combo_variations;
        if (! is_array($comboVariations) || $comboVariations === []) {
            return max(0, (int) round((float) $variation->variation_location_details()->sum('qty_available')));
        }

        /** @var ProductUtil $productUtil */
        $productUtil = app(ProductUtil::class);
        $locationIds = BusinessLocation::where('business_id', $business->id)
            ->where('is_active', 1)
            ->pluck('id');

        if ($locationIds->isEmpty()) {
            $locationIds = $variation->variation_location_details()->pluck('location_id')->unique();
        }

        $total = 0;
        foreach ($locationIds as $locationId) {
            $total += (int) $productUtil->calculateComboQuantity((int) $locationId, $comboVariations);
        }

        if ($total > 0) {
            return $total;
        }

        return max(0, (int) round((float) $variation->variation_location_details()->sum('qty_available')));
    }
}
