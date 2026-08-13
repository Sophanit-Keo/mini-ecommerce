<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductImageResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductSummaryResource;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\CatalogCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminCatalogController extends Controller
{
    public function __construct(private readonly RecordAdminAuditEvent $audit) {}

    public function products(Request $request): JsonResponse
    {
        $data = $request->validate([
            'perPage' => ['nullable', 'integer', 'between:1,100'],
            'active' => ['nullable', 'boolean'],
            'categoryId' => ['nullable', 'uuid'],
        ]);
        $query = Product::query()->with(['category', 'primaryImage', 'inventory'])->orderByDesc('id');

        if (array_key_exists('active', $data)) {
            $query->where('is_active', $data['active']);
        }

        if (isset($data['categoryId'])) {
            $category = Category::wherePublicId($data['categoryId'])->first();
            $query->where('category_id', $category?->id ?? 0);
        }

        $page = $query->paginate($data['perPage'] ?? 20);

        return response()->json([
            'data' => ProductSummaryResource::collection($page->items()),
            'page' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function storeProduct(StoreProductRequest $request): JsonResponse
    {
        $product = DB::transaction(function () use ($request): Product {
            $product = Product::create($request->productAttributes());
            Inventory::create([
                'product_id' => $product->id,
                'quantity_on_hand' => $request->input('initialStock', '0.000'),
                'quantity_reserved' => '0.000',
                'low_stock_threshold' => $request->input('lowStockThreshold', '0.000'),
                'restock_expected_at' => $request->input('restockExpectedAt'),
            ]);
            $this->audit->handle($request->user(), 'product.created', $product, null, $this->productSnapshot($product), $request);

            return $product;
        });

        CatalogCache::bust();

        return ProductResource::make($this->product($product))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function categories(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('perPage', 50), 1), 100);
        $page = Category::query()->with('parent')->withCount('products')->orderBy('position')->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => CategoryResource::collection($page->items()),
            'page' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $this->categoryData($request, false);
        $category = DB::transaction(function () use ($request, $data): Category {
            $category = Category::create($data);
            $this->audit->handle($request->user(), 'category.created', $category, null, $this->categorySnapshot($category), $request);

            return $category;
        });
        CatalogCache::bust();

        return CategoryResource::make($category->load(['parent'])->loadCount('products'))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateCategory(Request $request, string $categoryId): CategoryResource
    {
        $category = Category::wherePublicId($categoryId)->first();
        if ($category === null) {
            throw ProblemException::notFound('No such category.');
        }
        $data = $this->categoryData($request, true, $category);

        DB::transaction(function () use ($request, $category, $data): void {
            $locked = Category::query()->lockForUpdate()->findOrFail($category->id);
            $before = $this->categorySnapshot($locked);
            $locked->update($data);
            $this->audit->handle($request->user(), 'category.updated', $locked, $before, $this->categorySnapshot($locked), $request);
        });
        CatalogCache::bust();

        return CategoryResource::make($category->refresh()->load('parent')->loadCount('products'));
    }

    public function storeImage(Request $request, string $productId): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'altText' => ['nullable', 'string', 'max:200'],
            'position' => ['nullable', 'integer', 'min:0'],
            'isPrimary' => ['sometimes', 'boolean'],
        ]);
        $product = $this->findProduct($productId);

        $image = DB::transaction(function () use ($request, $data, $product): ProductImage {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            $images = $lockedProduct->images()->lockForUpdate()->orderBy('position')->get();
            $position = $data['position'] ?? (($images->max('position') ?? -1) + 1);

            if ($images->contains('position', $position)) {
                throw ProblemException::duplicateResource('A product image already uses that position.');
            }

            $primary = $request->boolean('isPrimary') || $images->isEmpty();
            if ($primary) {
                $lockedProduct->images()->where('is_primary', true)->update(['is_primary' => false]);
            }

            $image = $lockedProduct->images()->create([
                'url' => $data['url'],
                'alt_text' => $data['altText'] ?? null,
                'position' => $position,
                'is_primary' => $primary,
            ]);
            $this->audit->handle($request->user(), 'product_image.created', $lockedProduct, null, [
                'imageId' => $image->public_id, 'position' => $position, 'isPrimary' => $primary,
            ], $request);

            return $image;
        });
        CatalogCache::bust();

        return ProductImageResource::make($image)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function setPrimaryImage(Request $request, string $productId, string $imageId): ProductImageResource
    {
        $product = $this->findProduct($productId);

        $image = DB::transaction(function () use ($request, $product, $imageId): ProductImage {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            $images = $lockedProduct->images()->lockForUpdate()->get();
            $image = $images->first(fn (ProductImage $candidate): bool => $candidate->public_id === $imageId);
            if ($image === null) {
                throw ProblemException::notFound('No such product image.');
            }
            $before = ['imageId' => $image->public_id, 'isPrimary' => $image->is_primary];
            $lockedProduct->images()->where('is_primary', true)->update(['is_primary' => false]);
            $image->update(['is_primary' => true]);
            $this->audit->handle($request->user(), 'product_image.primary_changed', $lockedProduct, $before, ['imageId' => $image->public_id, 'isPrimary' => true], $request);

            return $image->fresh();
        });
        CatalogCache::bust();

        return ProductImageResource::make($image);
    }

    public function destroyImage(Request $request, string $productId, string $imageId): Response
    {
        $product = $this->findProduct($productId);

        DB::transaction(function () use ($request, $product, $imageId): void {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            $images = $lockedProduct->images()->lockForUpdate()->orderBy('position')->get();
            $image = $images->first(fn (ProductImage $candidate): bool => $candidate->public_id === $imageId);
            if ($image === null) {
                throw ProblemException::notFound('No such product image.');
            }

            $before = ['imageId' => $image->public_id, 'position' => $image->position, 'isPrimary' => $image->is_primary];
            $wasPrimary = $image->is_primary;
            $image->delete();
            if ($wasPrimary && $replacement = $images->first(fn (ProductImage $candidate): bool => $candidate->id !== $image->id)) {
                $replacement->update(['is_primary' => true]);
            }
            $this->audit->handle($request->user(), 'product_image.deleted', $lockedProduct, $before, null, $request);
        });
        CatalogCache::bust();

        return response()->noContent();
    }

    private function findProduct(string $productId): Product
    {
        $product = Product::query()->wherePublicId($productId)->first();
        if ($product === null) {
            throw ProblemException::notFound('No such product.');
        }

        return $product;
    }

    /** @return array<string, mixed> */
    private function categoryData(Request $request, bool $partial, ?Category $current = null): array
    {
        $rules = [
            'parentId' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'uuid'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'slug' => [$partial ? 'sometimes' : 'required', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:500'],
            'imageUrl' => ['nullable', 'url', 'max:500'],
            'position' => [$partial ? 'sometimes' : 'required', 'integer', 'min:0'],
            'isActive' => [$partial ? 'sometimes' : 'boolean'],
        ];
        $data = $request->validate($rules);
        $attributes = [];
        $map = ['name' => 'name', 'slug' => 'slug', 'description' => 'description', 'imageUrl' => 'image_url', 'position' => 'position', 'isActive' => 'is_active'];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        if (array_key_exists('parentId', $data)) {
            $parent = $data['parentId'] === null ? null : Category::wherePublicId($data['parentId'])->first();
            if ($data['parentId'] !== null && $parent === null) {
                throw ValidationException::withMessages(['parentId' => 'No such parent category.']);
            }
            if ($parent !== null && $current !== null && ($parent->id === $current->id || $this->isDescendant($parent, $current))) {
                throw ValidationException::withMessages(['parentId' => 'A category cannot be its own descendant.']);
            }
            $attributes['parent_id'] = $parent?->id;
        }

        return $attributes;
    }

    private function isDescendant(Category $candidate, Category $category): bool
    {
        $node = $candidate;
        while ($node->parent_id !== null) {
            if ($node->parent_id === $category->id) {
                return true;
            }
            $node = $node->parent()->firstOrFail();
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function productSnapshot(Product $product): array
    {
        return ['productId' => $product->public_id, 'sku' => $product->sku, 'slug' => $product->slug, 'isActive' => $product->is_active];
    }

    /** @return array<string, mixed> */
    private function categorySnapshot(Category $category): array
    {
        return ['categoryId' => $category->public_id, 'slug' => $category->slug, 'parentId' => $category->parent?->public_id, 'isActive' => $category->is_active];
    }

    private function product(Product $product): Product
    {
        return $product->load(['category', 'inventory', 'images' => fn ($query) => $query->orderBy('position')]);
    }
}
