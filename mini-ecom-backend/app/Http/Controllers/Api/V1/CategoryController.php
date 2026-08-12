<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\CatalogCache;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Public. Categories are shallow (two levels in the seeded catalogue), so the tree is built
 * with a single eager load rather than a recursive walk.
 */
class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse|SymfonyResponse
    {
        $asTree = $request->boolean('tree');

        $payload = CatalogCache::remember('categories', ['tree' => $asTree], function () use ($request, $asTree): array {
            $query = Category::query()
                ->where('is_active', true)
                ->withCount(['products' => fn (Builder $products) => $products->where('is_active', true)])
                ->orderBy('position')
                ->orderBy('name');

            if ($asTree) {
                // Roots with their children nested. Children carry their own product counts.
                $query->whereNull('parent_id')->with([
                    'children' => fn ($children) => $children
                        ->where('is_active', true)
                        ->withCount(['products' => fn (Builder $products) => $products->where('is_active', true)])
                        ->orderBy('position'),
                ]);
            } else {
                // parentId is part of the flat shape, so the parents come along for the ride —
                // one extra query for the whole page rather than one per row.
                $query->with('parent');
            }

            return ['data' => CategoryResource::collection($query->get())->resolve($request)];
        });

        return CatalogCache::response($request, $payload);
    }

    public function show(Request $request, string $categoryId): JsonResponse|SymfonyResponse
    {
        $payload = CatalogCache::remember('category', ['id' => $categoryId], function () use ($request, $categoryId): array {
            $category = Category::query()
                ->where('is_active', true)
                ->wherePublicId($categoryId)
                ->withCount(['products' => fn (Builder $products) => $products->where('is_active', true)])
                ->with([
                    'parent',
                    'children' => fn ($children) => $children
                        ->where('is_active', true)
                        ->withCount(['products' => fn (Builder $products) => $products->where('is_active', true)])
                        ->orderBy('position'),
                ])
                ->first();

            if ($category === null) {
                throw ProblemException::notFound('No such category.');
            }

            return CategoryResource::make($category)->resolve($request);
        });

        return CatalogCache::response($request, $payload);
    }
}
