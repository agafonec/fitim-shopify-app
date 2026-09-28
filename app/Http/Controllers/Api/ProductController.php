<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Shopify\ProductInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * GET /api/products?limit=20&cursor=...
     *
     * Walk the catalogue chunk by chunk: pass `page_info.end_cursor` back as
     * `cursor` until `page_info.has_next_page` is false.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ]);

        $page = $this->service($request)->paginate(
            (int) ($validated['limit'] ?? 20),
            $validated['cursor'] ?? null,
        );

        return response()->json([
            'data' => $page['products'],
            'page_info' => $page['page_info'],
        ]);
    }

    /**
     * GET /api/products/lookup?sku=... | ?barcode=... | ?product_id=...
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['required_without_all:barcode,product_id', 'prohibits:barcode,product_id', 'string'],
            'barcode' => ['required_without_all:sku,product_id', 'prohibits:sku,product_id', 'string'],
            'product_id' => ['required_without_all:sku,barcode', 'prohibits:sku,barcode', 'string', 'regex:/^(\d+|gid:\/\/shopify\/Product\/\d+)$/'],
        ]);

        $service = $this->service($request);

        $product = match (true) {
            isset($validated['sku']) => $service->findBySku($validated['sku']),
            isset($validated['barcode']) => $service->findByBarcode($validated['barcode']),
            default => $service->findByProductId($validated['product_id']),
        };

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json(['data' => $product]);
    }

    private function service(Request $request): ProductInventoryService
    {
        return new ProductInventoryService($request->user());
    }
}
