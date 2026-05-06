<?php

namespace App\Http\Controllers;

use App\Business;
use App\Services\WooCommerceProductImportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WooCommerceController extends Controller
{
    public function __construct(
        private WooCommerceProductImportService $importService
    ) {}

    /**
     * List products from WooCommerce for selection
     */
    public function listProducts(Request $request): JsonResponse
    {
        $businessId = $request->session()->get('business.id');
        $business = Business::findOrFail($businessId);

        if (! $business->hasWooCommerceApiCredentials()) {
            return response()->json([
                'success' => false,
                'message' => __('business.woocommerce_not_configured'),
            ], 403);
        }

        $page = (int) ($request->input('page', 1));
        $perPage = (int) ($request->input('per_page', 25));

        $result = $this->importService->fetchProducts($business, $page, $perPage);

        return response()->json($result);
    }

    /**
     * Import selected products from WooCommerce
     */
    public function importProducts(Request $request): JsonResponse
    {
        $businessId = $request->session()->get('business.id');
        $business = Business::findOrFail($businessId);

        if (! $business->hasWooCommerceApiCredentials()) {
            return response()->json([
                'success' => false,
                'message' => __('business.woocommerce_not_configured'),
            ], 403);
        }

        $productIds = $request->input('product_ids', []);
        if (empty($productIds)) {
            return response()->json([
                'success' => false,
                'message' => __('lang_v1.no_products_selected'),
            ], 422);
        }

        // First fetch all products to have the full data
        $allProducts = [];
        $page = 1;
        $perPage = 100;

        do {
            $result = $this->importService->fetchProducts($business, $page, $perPage);
            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 422);
            }

            $allProducts = array_merge($allProducts, $result['products'] ?? []);
            $page++;
        } while ($page <= ($result['pages'] ?? 1));

        // Filter to only selected IDs
        $selectedIds = array_map('intval', $productIds);

        $result = $this->importService->importProducts($business, $selectedIds, $allProducts);

        return response()->json($result);
    }
}