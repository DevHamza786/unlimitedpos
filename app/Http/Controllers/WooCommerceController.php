<?php

namespace App\Http\Controllers;

use App\Business;
use App\Services\WooCommerceProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WooCommerceController extends Controller
{
    public function __construct(
        private WooCommerceProductImportService $importService
    ) {}

    private function resolveBusiness(Request $request): Business
    {
        $businessId = (int) $request->session()->get('user.business_id', $request->session()->get('business.id'));

        return Business::findOrFail($businessId);
    }

    /**
     * List products from WooCommerce for selection
     */
    public function listProducts(Request $request): JsonResponse
    {
        $business = $this->resolveBusiness($request);

        if (! $business->hasWooCommerceApiCredentials()) {
            return response()->json([
                'success' => false,
                'message' => __('business.woocommerce_not_configured'),
            ], 403);
        }

        $page = (int) ($request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));

        $result = $this->importService->fetchProducts($business, $page, $perPage, ['status' => 'any']);

        return response()->json($result);
    }

    /**
     * Import selected products from WooCommerce
     */
    public function importProducts(Request $request): JsonResponse
    {
        @ini_set('memory_limit', '512M');
        set_time_limit(180);

        try {
            $business = $this->resolveBusiness($request);

            if (! $business->hasWooCommerceApiCredentials()) {
                return response()->json([
                    'success' => false,
                    'message' => __('business.woocommerce_not_configured'),
                ], 403);
            }

            $productIds = $request->input('product_ids', []);
            if (! is_array($productIds) || $productIds === []) {
                return response()->json([
                    'success' => false,
                    'message' => __('lang_v1.no_products_selected'),
                ], 422);
            }

            $selectedIds = array_values(array_unique(array_map('intval', $productIds)));

            $fetch = $this->importService->fetchProductsByIds($business, $selectedIds);
            if (! $fetch['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $fetch['message'],
                ], 422);
            }

            $result = $this->importService->importProducts(
                $business,
                $selectedIds,
                $fetch['products'] ?? []
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            \Log::error('WooCommerce import products: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => __('business.woocommerce_import_server_error'),
            ], 500);
        }
    }
}
