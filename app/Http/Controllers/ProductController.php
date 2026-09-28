<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductIndexRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Support\ProductCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(ProductIndexRequest $request, ProductCache $cache): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $page = (int) ($validated['page'] ?? 1);
        $perPage = $request->pageSize();
        $filters = Arr::except($validated, ['page', 'per_page']);

        return ProductResource::collection($cache->products($filters, $page, $perPage));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $product = DB::transaction(function () use ($data) {
            $product = Product::create(Arr::except($data, 'supplier_ids'));
            $product->suppliers()->sync($data['supplier_ids'] ?? []);
            ProductCache::invalidateAfterCommit();

            return $product->load(['category', 'suppliers']);
        });

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Product $product, ProductCache $cache): ProductResource
    {
        return new ProductResource($cache->product($product));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $data = $request->validated();
        $product = DB::transaction(function () use ($product, $data) {
            $attributes = Arr::except($data, 'supplier_ids');
            if (request()->isMethod('put') && ! array_key_exists('description', $attributes)) {
                $attributes['description'] = null;
            }
            $product->update($attributes);
            if (array_key_exists('supplier_ids', $data)) {
                $product->suppliers()->sync($data['supplier_ids']);
                ProductCache::invalidateAfterCommit();
            }

            return $product->refresh()->load(['category', 'suppliers']);
        });

        return new ProductResource($product);
    }

    public function destroy(Product $product): Response
    {
        $product->delete();

        return response()->noContent();
    }
}
