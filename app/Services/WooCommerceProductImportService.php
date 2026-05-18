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
    public function fetchProducts(Business $business, int $page = 1, int $perPage = 25): array
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
                ->get($base.'/wp-json/wc/v3/products', [
                    'page' => $page,
                    'per_page' => $perPage,
                ]);

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

    private function createProduct(Business $business, array $wooProduct): array
    {
        $wooId = (int) $wooProduct['id'];
        $type = ($wooProduct['type'] ?? 'simple') === 'variable' ? 'variable' : 'single';

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
            $this->createVariableVariations($business, $product, $wooProduct);
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

        // Update variation price and stock
        if ($product->type === 'single') {
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

    private function createVariableVariations(Business $business, Product $product, array $wooProduct): void
    {
        $variations = $wooProduct['variations'] ?? [];

        if ($this->variationsAreIds($variations)) {
            $wooProductId = (int) ($wooProduct['id'] ?? $product->woocommerce_product_id);
            $variations = $this->fetchProductVariations($business, $wooProductId);
        }

        // If no variations in response, create a default one
        if (empty($variations)) {
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

            return;
        }

        // Create variations from WooCommerce data
        foreach ($variations as $idx => $wooVar) {
            if (! is_array($wooVar)) {
                continue;
            }

            $varName = $this->buildVariationLabel($wooVar, $idx);
            $varPrice = $this->extractWooPrice($wooVar);
            $varStock = (int) ($wooVar['stock_quantity'] ?? 0);
            $varSku = ! empty($wooVar['sku']) ? $wooVar['sku'] : $product->sku.'-'.$idx;

            $productVariation = ProductVariation::create([
                'product_id' => $product->id,
                'name' => $varName,
                'is_dummy' => 0,
            ]);

            $variation = Variation::create([
                'product_id' => $product->id,
                'product_variation_id' => $productVariation->id,
                'name' => $varName,
                'sub_sku' => $varSku,
                'sell_price_inc_tax' => $varPrice,
                'woocommerce_variation_id' => ! empty($wooVar['id']) ? (int) $wooVar['id'] : null,
            ]);

            if ($product->enable_stock) {
                $this->ensureVariationLocationStock($product, $variation, $varStock);
            }
        }
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