<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public. Categories are shallow (two levels in the seeded catalogue), so the tree is built
 * with a single eager load rather than a recursive walk.
 */
class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $asTree = $request->boolean('tree');

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

        return response()->json(['data' => CategoryResource::collection($query->get())]);
    }

    public function show(string $categoryId): CategoryResource
    {
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

        return CategoryResource::make($category);
    }
}
