<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SoldBy;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListProductsRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductSummaryResource;
use App\Models\Category;
use App\Models\Product;
use App\Support\CursorPage;
use App\Support\SortKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public catalogue. Everything here is keyset-paginated and eager-loaded — a product list is
 * the hottest read in the application and the one most likely to become an N+1.
 */
class ProductController extends Controller
{
    private const RELEVANCE_SQL = 'MATCH(products.name, products.brand, products.description) AGAINST (? IN NATURAL LANGUAGE MODE)';

    public function index(ListProductsRequest $request): JsonResponse
    {
        $term = $request->searchTerm();

        $query = $term === null
            ? tap($this->baseQuery(), fn (Builder $q) => $this->applyFilters($q, $request))
            : $this->searchQuery($term, $request);

        $page = CursorPage::build(
            $query,
            $this->sortKeys($request->sort(), $term),
            $request->limit(),
            $request->query('cursor'),
        );

        return response()->json([
            'data' => ProductSummaryResource::collection($page->items),
            'page' => $page->toPageInfo(),
        ]);
    }

    /**
     * Search runs against a derived table that materialises the relevance score as a DECIMAL.
     *
     * Two things make the direct approach fail, and both are worth recording because both
     * look like they should work:
     *
     * 1. MySQL forbids a select alias in a WHERE clause, so the obvious fix is to repeat
     *    `MATCH(...) AGAINST(...)` inside the keyset predicate. A second occurrence does not
     *    compare equal to the score the first one produced — `MATCH(...) <=> 1.5516651868`
     *    is false for a row whose score is exactly that.
     * 2. The score is a FLOAT, so even against a materialised column an equality test on the
     *    value the client round-tripped through JSON does not hold.
     *
     * Either failure silently truncates the result set: page two comes back empty and the
     * search looks like it found four rows when it found forty. Selecting the score in a
     * subquery and casting it to DECIMAL fixes both — the cursor then compares an exact
     * decimal against a real column. The subquery is aliased back to `products` so column
     * references and eager loads are unaffected.
     *
     * Full-text search ignores tokens shorter than innodb_ft_min_token_size (3 by default),
     * so a two-character query returns nothing rather than erroring.
     *
     * @return Builder<Product>
     */
    private function searchQuery(string $term, ListProductsRequest $request): Builder
    {
        $matches = Product::query()
            ->select('products.*')
            ->selectRaw('CAST('.self::RELEVANCE_SQL.' AS DECIMAL(20,10)) AS relevance_score', [$term])
            ->where('products.is_active', true)
            ->whereRaw(self::RELEVANCE_SQL, [$term]);

        $this->applyFilters($matches, $request);

        return Product::query()
            ->fromSub($matches, 'products')
            ->with(['category', 'primaryImage', 'inventory']);
    }

    public function show(string $productId): ProductResource
    {
        $product = $this->baseQuery()
            ->with(['images' => fn ($images) => $images->orderBy('position')])
            ->wherePublicId($productId)
            ->first();

        if ($product === null) {
            throw ProblemException::notFound('No such product.');
        }

        return ProductResource::make($product);
    }

    /**
     * In-stock alternatives from the same category, closest in price first.
     *
     * Shown to the customer when they pick a substitution preference, and to the picker when
     * a line cannot be filled. Same pricing shape is preferred: swapping a loose weight item
     * for a pre-packed one changes what the customer is charged for in a way a like-for-like
     * swap does not.
     */
    public function substitutes(Request $request, string $productId): JsonResponse
    {
        $limit = min(max((int) ($request->query('limit') ?? 5), 1), 20);

        $product = Product::where('is_active', true)->wherePublicId($productId)->first();

        if ($product === null) {
            throw ProblemException::notFound('No such product.');
        }

        $substitutes = $this->baseQuery()
            ->where('category_id', $product->category_id)
            ->whereKeyNot($product->id)
            ->whereHas('inventory', fn (Builder $inventory) => $inventory->where('quantity_available', '>', 0))
            ->orderByRaw('products.sold_by = ? DESC', [$product->sold_by->value])
            ->orderByRaw('ABS(products.effective_price - ?) ASC', [$product->effective_price])
            ->orderBy('products.id')
            ->limit($limit)
            ->get();

        return response()->json(['data' => ProductSummaryResource::collection($substitutes)]);
    }

    /**
     * @return Builder<Product>
     */
    private function baseQuery(): Builder
    {
        return Product::query()
            ->where('products.is_active', true)
            ->with(['category', 'primaryImage', 'inventory']);
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applyFilters(Builder $query, ListProductsRequest $request): void
    {
        if ($categoryId = $request->query('categoryId')) {
            // Restricts to the category itself, not its descendants — the spec is explicit
            // that this filter does not widen.
            $category = Category::wherePublicId((string) $categoryId)->first();

            $query->where('products.category_id', $category?->id ?? 0);
        }

        if ($request->boolean('inStock')) {
            $query->whereHas('inventory', fn (Builder $inventory) => $inventory->where('quantity_available', '>', 0));
        }

        if ($soldBy = $request->query('soldBy')) {
            $query->where('products.sold_by', SoldBy::from((string) $soldBy));
        }
    }

    /**
     * The last key is always `products.id`, which is unique — without a unique tiebreaker a
     * keyset cursor can stall or repeat rows whose sort values are equal.
     *
     * @return array<int, SortKey>
     */
    private function sortKeys(string $sort, ?string $term): array
    {
        $id = fn (string $direction) => new SortKey(
            'products.id',
            $direction,
            fn (Product $product) => $product->id,
        );

        return match ($sort) {
            // Sorted on effective_price, never `price`: the latter is null for every
            // weight-priced product, so they would collate as one block and rank ahead of or
            // behind the entire catalogue regardless of what they cost (finding R-01).
            'price_asc' => [
                new SortKey('products.effective_price', 'asc', fn (Product $p) => $p->effective_price),
                $id('asc'),
            ],
            'price_desc' => [
                new SortKey('products.effective_price', 'desc', fn (Product $p) => $p->effective_price),
                $id('desc'),
            ],
            'newest' => [
                new SortKey('products.created_at', 'desc', fn (Product $p) => $p->created_at->format('Y-m-d H:i:s.v')),
                $id('desc'),
            ],
            // Relevance only means something alongside a query. Without one it degrades to
            // newest-first rather than to an arbitrary order.
            //
            // `relevance_score` is a real column here — see searchQuery() for why it has to be.
            default => $term === null
                ? [$id('desc')]
                : [
                    // Kept as a string end to end. Casting to float here would reintroduce
                    // exactly the precision loss the DECIMAL cast exists to avoid.
                    new SortKey(
                        'products.relevance_score',
                        'desc',
                        fn (Product $p) => (string) $p->relevance_score,
                    ),
                    $id('desc'),
                ],
        };
    }
}
