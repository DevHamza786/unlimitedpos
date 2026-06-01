<?php

namespace App\Services;

use App\Business;
use App\BusinessLocation;
use App\Category;
use App\Product;
use App\Unit;
use App\Variation;
use App\ProductVariation;
use App\VariationLocationDetails;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WooCommerceProductImportService
{
    private const API_TIMEOUT = 90;

    public function businessIsConfigured(Business $business): bool
    {
        return $business->hasWooCommerceApiCredentials();
    }

    /**
     * Fetch products from WooCommerce API
     *
     * @return array{success: bool, message: string, products?: array, total?: int, pages?: int}
     */
    public function fetchProducts(Business $business, int $page = 1, int $perPage = 25, array $extraParams = []): array
    {
        if (! $this->businessIsConfigured($business)) {
            return ['success' => false, 'message' => __('business.woocommerce_not_configured')];
        }

        $base = rtrim((string) $business->woocommerce_store_url, '/');
        $verify = (bool) config('constants.woocommerce_verify_ssl', true);

        try {
            $response = Http::withBasicAuth(
                $business->woocommerce_consumer_key,
                $business->woocommerce_consumer_secret
            )
                ->withOptions(['verify' => $verify])
                ->acceptJson()
                ->timeout(self::API_TIMEOUT)
                ->get($base.'/wp-json/wc/v3/products', array_merge([
                    'page' => $page,
                    'per_page' => $perPage,
                ], $extraParams));

            if (! $response->successful()) {
                $body = $response->json();
                $msg = is_array($body) && ! empty($body['message']) ? $body['message'] : $response->body();

                return [
                    'success' => false,
                    'message' => __('business.woocommerce_fetch_failed').': '.substr(strip_tags((string) $msg), 0, 300),
                ];
            }

            $products = $response->json();
            $total = (int) $response->header('X-WP-Total', count($products));
            $totalPages = (int) $response->header('X-WP-TotalPages', 1);

            foreach ($products as $i => $product) {
                $products[$i]['display_price'] = $this->resolveDisplayPrice($business, $product);
            }

            return [
                'success' => true,
                'message' => '',
                'products' => $products,
                'total' => $total,
                'pages' => $totalPages,
            ];
        } catch (\Throwable $e) {
            Log::warning('WooCommerce fetch products: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Import a single product from WooCommerce to POS
     *
     * @return array{success: bool, message: string, product_id?: int|null}
     */
    public function importProduct(Business $business, array $wooProduct): array
    {
        if (! $this->businessIsConfigured($business)) {
            return ['success' => false, 'message' => __('business.woocommerce_not_configured')];
        }

        $wooId = (int) ($wooProduct['id'] ?? 0);
        if ($wooId === 0) {
            return ['success' => false, 'message' => __('messages.something_went_wrong')];
        }

        // Check if product already exists (by woo_product_id)
        $existing = Product::where('business_id', $business->id)
            ->where('woocommerce_product_id', $wooId)
            ->first();

        if ($existing) {
            return $this->updateProduct($business, $existing, $wooProduct);
        }

        return $this->createProduct($business, $wooProduct);
    }

    /**
     * Import multiple products
     *
     * @param  array<int>  $wooProductIds
     * @return array{success: bool, message: string, ok?: int, fail?: int}
     */
    public function importProducts(Business $business, array $wooProductIds, array $allWooProducts): array
    {
        $ok = 0;
        $fail = 0;
        $errors = [];

        foreach ($wooProductIds as $wooId) {
            // Find the product in the provided list
            $wooProduct = null;
            foreach ($allWooProducts as $p) {
                if ((int) ($p['id'] ?? 0) === $wooId) {
                    $wooProduct = $p;
                    break;
                }
            }

            if ($wooProduct === null) {
                $fail++;
                $errors[] = "ID $wooId: Product not found";

                continue;
            }

            $result = $this->importProduct($business, $wooProduct);
            if ($result['success']) {
                $ok++;
            } else {
                $fail++;
                $errors[] = $wooProduct['name'].': '.$result['message'];
            }
        }

        $msg = __('business.woocommerce_import_result', ['ok' => $ok, 'fail' => $fail]);
        if (! empty($errors)) {
            $msg .= ' | '.implode(' | ', array_slice($errors, 0, 5));
        }

        return [
            'success' => $fail === 0,
            'message' => $msg,
            'ok' => $ok,
            'fail' => $fail,
        ];
    }

    /**
     * Auto-sync products from WooCommerce (used by the scheduled command).
     * When $modifiedAfter is provided, only products created/updated after
     * that time are fetched, keeping the cron lightweight.
     *
     * @return array{success: bool, message: string, created: int, updated: int, failed: int}
     */
    public function syncProducts(Business $business, ?\Carbon\Carbon $modifiedAfter = null): array
    {
        if (! $this->businessIsConfigured($business)) {
            return ['success' => false, 'message' => __('business.woocommerce_not_configured'), 'created' => 0, 'updated' => 0, 'failed' => 0];
        }

        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        $extraParams = [];
        if ($modifiedAfter !== null) {
            // WooCommerce expects ISO8601; this filters by modified date.
            $extraParams['modified_after'] = $modifiedAfter->toIso8601String();
        }

        $page = 1;
        $perPage = 100;

        do {
            $result = $this->fetchProducts($business, $page, $perPage, $extraParams);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'message' => $result['message'],
                    'created' => $created,
                    'updated' => $updated,
                    'failed' => $failed,
                ];
            }

            $products = $result['products'] ?? [];

            foreach ($products as $wooProduct) {
                $existsBefore = Product::where('business_id', $business->id)
                    ->where('woocommerce_product_id', (int) ($wooProduct['id'] ?? 0))
                    ->exists();

                $importResult = $this->importProduct($business, $wooProduct);

                if (! empty($importResult['success'])) {
                    $existsBefore ? $updated++ : $created++;
                } else {
                    $failed++;
                    $errors[] = ($wooProduct['name'] ?? 'Unknown').': '.($importResult['message'] ?? '');
                }
            }

            $totalPages = (int) ($result['pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        $message = "Created: $created, Updated: $updated, Failed: $failed";
        if (! empty($errors)) {
            $message .= ' | '.implode(' | ', array_slice($errors, 0, 5));
        }

        // success = the WooCommerce API calls completed (individual product
        // failures are reported but must not block the sync watermark).
        return [
            'success' => true,
            'message' => $message,
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
        ];
    }

    private function createProduct(Business $business, array $wooProduct): array
    {
        $wooId = (int) $wooProduct['id'];
        $type = $this->resolveWooType($wooProduct);

        // Get or create category
        $categoryId = $this->resolveCategory($business, $wooProduct);

        // Get default unit
        $unitId = $this->resolveUnit($business);

        // Handle image
        $imagePath = $this->downloadImage($business, $wooProduct);

        // Generate SKU
        $sku = $this->generateSku($business, $wooProduct);

        // Determine stock settings
        $enableStock = (bool) ($wooProduct['manage_stock'] ?? false);
        $stockQty = (int) ($wooProduct['stock_quantity'] ?? 0);
        $price = $this->extractWooPrice($wooProduct);

        // Create product
        $product = Product::create([
            'name' => $wooProduct['name'] ?? 'Untitled Product',
            'business_id' => $business->id,
            'type' => $type,
            'unit_id' => $unitId,
            'category_id' => $categoryId,
            'sku' => $sku,
            'enable_stock' => $enableStock,
            'alert_quantity' => 10,
            'product_description' => $this->cleanHtml($wooProduct['description'] ?? ''),
            'image' => $imagePath,
            'woocommerce_product_id' => $wooId,
            'created_by' => $business->owner_id,
        ]);

        if ($type === 'single') {
            $this->createSingleVariation($product, $price, $stockQty, $enableStock);
        } else {
            $this->syncVariableVariations($business, $product, $wooProduct);
        }

        $this->syncProductLocations($product);

        return [
            'success' => true,
            'message' => __('business.woocommerce_product_imported'),
            'product_id' => $product->id,
        ];
    }

    private function updateProduct(Business $business, Product $product, array $wooProduct): array
    {
        // Update basic fields
        $product->name = $wooProduct['name'] ?? $product->name;
        $product->product_description = $this->cleanHtml($wooProduct['description'] ?? '');
        $product->enable_stock = (bool) ($wooProduct['manage_stock'] ?? false) ? 1 : 0;

        $newImage = $this->downloadImage($business, $wooProduct);
        if (empty($product->image) || ($newImage !== null && $product->image !== $newImage)) {
            $product->image = $newImage ?? $product->image;
        }

        $product->save();

        $wooType = $this->resolveWooType($wooProduct);

        if ($wooType === 'variable') {
            // Converts an existing single product to variable (if needed) and
            // (re)syncs every WooCommerce variation/combination.
            $this->syncVariableVariations($business, $product, $wooProduct);
        } else {
            // Make sure the product is single, then refresh price and stock
            if ($product->type !== 'single') {
                $product->type = 'single';
                $product->save();
            }

            $variation = $product->variations()->whereNull('deleted_at')->first();
            if ($variation) {
                $price = $this->extractWooPrice($wooProduct);
                $variation->sell_price_inc_tax = $price;
                $variation->save();

                $enableStock = (bool) ($wooProduct['manage_stock'] ?? false);
                $stockQty = (int) ($wooProduct['stock_quantity'] ?? 0);
                if ($enableStock) {
                    $this->syncSingleVariationStock($product, $variation, $stockQty);
                }
            }
        }

        $this->syncProductLocations($product);

        return [
            'success' => true,
            'message' => __('business.woocommerce_product_updated'),
            'product_id' => $product->id,
        ];
    }

    private function createSingleVariation(Product $product, float $price, int $stockQty, bool $enableStock): void
    {
        $productVariation = ProductVariation::create([
            'product_id' => $product->id,
            'name' => 'Default',
            'is_dummy' => 1,
        ]);

        $variation = Variation::create([
            'product_id' => $product->id,
            'product_variation_id' => $productVariation->id,
            'name' => 'Default',
            'sub_sku' => $product->sku,
            'sell_price_inc_tax' => $price,
        ]);

        // Add stock if enabled
        if ($enableStock) {
            $this->ensureVariationLocationStock($product, $variation, $stockQty);
        }
    }

    /**
     * Creates or (re)syncs all WooCommerce variations for a variable product.
     *
     * Handles three cases:
     *  - fresh import of a variable product
     *  - converting a product that was previously imported as "single"
     *  - re-importing an already-variable product (matched by woocommerce_variation_id)
     *
     * UltimatePOS supports a single variation dimension, so multi-attribute
     * WooCommerce products (e.g. Size x Leg length) are flattened into one
     * dimension with combined value labels like "24 / S".
     */
    private function syncVariableVariations(Business $business, Product $product, array $wooProduct): void
    {
        $wooProductId = (int) ($wooProduct['id'] ?? $product->woocommerce_product_id);

        $variations = $wooProduct['variations'] ?? [];
        if (empty($variations) || $this->variationsAreIds($variations)) {
            $variations = $this->fetchProductVariations($business, $wooProductId);
        }

        // Keep only the actual variation objects
        $variations = array_values(array_filter($variations, 'is_array'));

        // Nothing usable from WooCommerce — keep a safe placeholder and stop
        if (empty($variations)) {
            $this->ensureVariableDefault($product);

            return;
        }

        // Ensure the product is flagged as variable
        if ($product->type !== 'variable') {
            $product->type = 'variable';
            $product->save();
        }

        $attributeLabel = $this->buildVariationAttributeLabel($variations[0]);

        // UltimatePOS expects exactly one product_variation (dimension).
        // Rebuild from scratch if the structure is off (0 or multiple rows
        // from older/broken imports); otherwise reuse the existing one.
        $existingPvs = ProductVariation::where('product_id', $product->id)->get();

        if ($existingPvs->count() !== 1) {
            Variation::where('product_id', $product->id)->delete();
            ProductVariation::where('product_id', $product->id)->delete();

            $productVariation = ProductVariation::create([
                'product_id' => $product->id,
                'name' => $attributeLabel,
                'is_dummy' => 0,
            ]);
        } else {
            $productVariation = $existingPvs->first();
            $productVariation->name = $attributeLabel;
            $productVariation->is_dummy = 0;
            $productVariation->save();

            // Drop any dummy "Default" variation left over from a single import
            Variation::where('product_variation_id', $productVariation->id)
                ->whereNull('woocommerce_variation_id')
                ->delete();
        }

        // Existing variations keyed by their WooCommerce id (for upsert)
        $existing = Variation::where('product_variation_id', $productVariation->id)
            ->whereNotNull('woocommerce_variation_id')
            ->get()
            ->keyBy('woocommerce_variation_id');

        foreach ($variations as $idx => $wooVar) {
            $wooVarId = ! empty($wooVar['id']) ? (int) $wooVar['id'] : null;
            $varName = $this->buildVariationLabel($wooVar, $idx);
            $varPrice = $this->extractWooPrice($wooVar);
            $varStock = (int) ($wooVar['stock_quantity'] ?? 0);
            $varSku = ! empty($wooVar['sku']) ? $wooVar['sku'] : $product->sku.'-'.($idx + 1);

            $variation = ($wooVarId !== null && isset($existing[$wooVarId])) ? $existing[$wooVarId] : null;

            if ($variation) {
                $variation->name = $varName;
                $variation->sub_sku = $varSku;
                $variation->sell_price_inc_tax = $varPrice;
                $variation->default_sell_price = $varPrice;
                $variation->save();
            } else {
                $variation = Variation::create([
                    'product_id' => $product->id,
                    'product_variation_id' => $productVariation->id,
                    'name' => $varName,
                    'sub_sku' => $varSku,
                    'sell_price_inc_tax' => $varPrice,
                    'default_sell_price' => $varPrice,
                    'woocommerce_variation_id' => $wooVarId,
                ]);
            }

            if ($product->enable_stock) {
                $this->ensureVariationLocationStock($product, $variation, $varStock);
            }
        }
    }

    /**
     * Fallback when a variable product returns no usable variation data.
     */
    private function ensureVariableDefault(Product $product): void
    {
        if (ProductVariation::where('product_id', $product->id)->exists()) {
            return;
        }

        $productVariation = ProductVariation::create([
            'product_id' => $product->id,
            'name' => 'Default',
            'is_dummy' => 1,
        ]);

        Variation::create([
            'product_id' => $product->id,
            'product_variation_id' => $productVariation->id,
            'name' => 'Default',
            'sub_sku' => $product->sku,
            'sell_price_inc_tax' => 0,
        ]);
    }

    /**
     * Robustly determine whether a WooCommerce product is variable.
     */
    private function resolveWooType(array $wooProduct): string
    {
        if (($wooProduct['type'] ?? '') === 'variable') {
            return 'variable';
        }

        if (! empty($wooProduct['variations'])) {
            return 'variable';
        }

        foreach ($wooProduct['attributes'] ?? [] as $attr) {
            if (is_array($attr) && ! empty($attr['variation'])) {
                return 'variable';
            }
        }

        return 'single';
    }

    /**
     * Attach product to all business locations so the "Business location" column and filters work.
     */
    private function syncProductLocations(Product $product): void
    {
        $locationIds = BusinessLocation::where('business_id', $product->business_id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (! empty($locationIds)) {
            $product->product_locations()->sync($locationIds);
        }
    }

    private function ensureVariationLocationStock(Product $product, Variation $variation, int $stockQty): void
    {
        $defaultLocation = BusinessLocation::where('business_id', $product->business_id)
            ->orderBy('id')
            ->first();

        if (! $defaultLocation) {
            return;
        }

        VariationLocationDetails::updateOrCreate(
            [
                'variation_id' => $variation->id,
                'product_id' => $product->id,
                'location_id' => $defaultLocation->id,
            ],
            [
                'product_variation_id' => $variation->product_variation_id,
                'qty_available' => $stockQty,
            ]
        );
    }

    private function syncSingleVariationStock(Product $product, Variation $variation, int $stockQty): void
    {
        $details = $variation->variation_location_details;

        if ($details->isEmpty()) {
            $this->ensureVariationLocationStock($product, $variation, $stockQty);

            return;
        }

        foreach ($details as $vld) {
            $vld->qty_available = $stockQty;
            if (empty($vld->product_variation_id)) {
                $vld->product_variation_id = $variation->product_variation_id;
            }
            $vld->save();
        }
    }

    private function resolveCategory(Business $business, array $wooProduct): ?int
    {
        $categories = $wooProduct['categories'] ?? [];

        if (empty($categories)) {
            return null;
        }

        // Try to match by name
        $wooCatName = is_array($categories[0] ?? null) ? ($categories[0]['name'] ?? null) : $categories[0];
        if (empty($wooCatName)) {
            return null;
        }

        // Check if category exists in POS
        $category = Category::where('business_id', $business->id)
            ->where('name', $wooCatName)
            ->first();

        if ($category) {
            return $category->id;
        }

        // Create new category
        $newCategory = Category::create([
            'name' => $wooCatName,
            'business_id' => $business->id,
            'created_by' => $business->owner_id,
        ]);

        return $newCategory->id;
    }

    private function resolveUnit(Business $business): int
    {
        // Try to find "each" unit
        $unit = Unit::where('business_id', $business->id)
            ->where('short_name', 'pc')
            ->orWhere('actual_name', 'Piece')
            ->orWhere('actual_name', 'Each')
            ->first();

        if ($unit) {
            return $unit->id;
        }

        // Get first unit
        $unit = Unit::where('business_id', $business->id)->first();
        if ($unit) {
            return $unit->id;
        }

        // Create default unit
        $unit = Unit::create([
            'business_id' => $business->id,
            'actual_name' => 'Piece',
            'short_name' => 'pc',
            'allow_decimal' => 0,
        ]);

        return $unit->id;
    }

    private function generateSku(Business $business, array $wooProduct): string
    {
        $sku = $wooProduct['sku'] ?? '';

        if (! empty($sku)) {
            // Check for duplicates
            $exists = Product::where('business_id', $business->id)
                ->where('sku', $sku)
                ->exists();

            if (! $exists) {
                return $sku;
            }

            // Add suffix to make unique
            $sku = $sku.'-'.Str::random(4);
        }

        // Generate SKU from product name if still empty
        if (empty($sku)) {
            $name = Str::slug($wooProduct['name'] ?? 'product');
            $sku = 'woo-'.$name.'-'.Str::random(4);
        }

        return $sku;
    }

    private function downloadImage(Business $business, array $wooProduct): ?string
    {
        $images = $wooProduct['images'] ?? [];
        if (empty($images)) {
            return null;
        }

        $imageUrl = is_array($images[0] ?? null) ? ($images[0]['src'] ?? null) : $images[0];
        if (empty($imageUrl)) {
            return null;
        }

        // Validate URL
        $host = parse_url($imageUrl, PHP_URL_HOST);
        if (! $host || in_array(strtolower($host), ['127.0.0.1', 'localhost'], true)) {
            return null;
        }

        $verify = (bool) config('constants.woocommerce_verify_ssl', true);

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'DollydustcountryPOS-WooCommerce-Import/1.0',
            ])
                ->withOptions(['verify' => $verify])
                ->timeout(30)
                ->get($imageUrl);
            if (! $response->successful()) {
                return null;
            }

            $content = $response->body();
            $mimeType = $response->header('Content-Type', 'image/jpeg');

            // Determine extension
            $ext = 'jpg';
            if (str_contains($mimeType, 'png')) {
                $ext = 'png';
            } elseif (str_contains($mimeType, 'gif')) {
                $ext = 'gif';
            } elseif (str_contains($mimeType, 'webp')) {
                $ext = 'webp';
            }

            $filename = 'woo-'.time().'-'.Str::random(8).'.'.$ext;
            $relativeDir = config('constants.product_img_path');
            $dir = public_path('uploads'.DIRECTORY_SEPARATOR.$relativeDir);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $path = $dir.DIRECTORY_SEPARATOR.$filename;

            file_put_contents($path, $content);

            return $filename;
        } catch (\Throwable $e) {
            Log::warning('WooCommerce image download failed: '.$e->getMessage());

            return null;
        }
    }

    private function cleanHtml(string $html): string
    {
        // Strip HTML tags but keep basic formatting
        $text = strip_tags($html, '<p><br><strong><em><ul><ol><li>');

        return trim($text);
    }

    /**
     * WooCommerce variable parents often have empty regular_price; prices live on variations.
     */
    private function resolveDisplayPrice(Business $business, array $wooProduct): string
    {
        $type = $wooProduct['type'] ?? 'simple';
        $parentPrice = $this->extractWooPrice($wooProduct);

        if ($type !== 'variable') {
            return $parentPrice > 0 ? number_format($parentPrice, 2, '.', '') : '0';
        }

        if ($parentPrice > 0) {
            return number_format($parentPrice, 2, '.', '');
        }

        $variations = $wooProduct['variations'] ?? [];
        if ($this->variationsAreIds($variations)) {
            $variations = $this->fetchProductVariations($business, (int) ($wooProduct['id'] ?? 0));
        }

        $prices = [];
        foreach ($variations as $variation) {
            if (! is_array($variation)) {
                continue;
            }
            $price = $this->extractWooPrice($variation);
            if ($price > 0) {
                $prices[] = $price;
            }
        }

        if (empty($prices)) {
            return '0';
        }

        $min = min($prices);
        $max = max($prices);

        if (abs($min - $max) < 0.001) {
            return number_format($min, 2, '.', '');
        }

        return number_format($min, 2, '.', '').' - '.number_format($max, 2, '.', '');
    }

    private function extractWooPrice(array $item): float
    {
        foreach (['regular_price', 'price', 'sale_price'] as $key) {
            $val = $item[$key] ?? '';
            if ($val !== '' && $val !== null && is_numeric($val)) {
                return (float) $val;
            }
        }

        return 0.0;
    }

    private function variationsAreIds(array $variations): bool
    {
        if (empty($variations)) {
            return false;
        }

        $first = $variations[0];

        return is_int($first) || (is_string($first) && ctype_digit($first));
    }

    private function buildVariationLabel(array $wooVar, int $index): string
    {
        $parts = [];
        foreach ($wooVar['attributes'] ?? [] as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $option = trim((string) ($attr['option'] ?? ''));
            if ($option !== '') {
                $parts[] = $option;
            }
        }

        if (! empty($parts)) {
            return implode(' / ', $parts);
        }

        return $wooVar['name'] ?? 'Variation '.($index + 1);
    }

    /**
     * Attribute dimension label(s) for product_variations.name (e.g. Size / Colour).
     */
    private function buildVariationAttributeLabel(array $wooVar): string
    {
        $names = [];
        foreach ($wooVar['attributes'] ?? [] as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $name = trim((string) ($attr['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if (! empty($names)) {
            return implode(' / ', $names);
        }

        return 'Variation';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductVariations(Business $business, int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $base = rtrim((string) $business->woocommerce_store_url, '/');
        $verify = (bool) config('constants.woocommerce_verify_ssl', true);
        $all = [];
        $page = 1;

        try {
            do {
                $response = Http::withBasicAuth(
                    $business->woocommerce_consumer_key,
                    $business->woocommerce_consumer_secret
                )
                    ->withOptions(['verify' => $verify])
                    ->acceptJson()
                    ->timeout(self::API_TIMEOUT)
                    ->get($base.'/wp-json/wc/v3/products/'.$productId.'/variations', [
                        'page' => $page,
                        'per_page' => 100,
                    ]);

                if (! $response->successful()) {
                    break;
                }

                $batch = $response->json();
                if (! is_array($batch) || empty($batch)) {
                    break;
                }

                $all = array_merge($all, $batch);
                $totalPages = (int) $response->header('X-WP-TotalPages', 1);
                $page++;
            } while ($page <= $totalPages);

            return $all;
        } catch (\Throwable $e) {
            Log::warning('WooCommerce fetch variations: '.$e->getMessage());

            return [];
        }
    }
}