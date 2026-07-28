<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The catalogue is the only public surface: no token, throttled per IP.

// ---------------------------------------------------------------------------
// Categories
// ---------------------------------------------------------------------------

test('categories are public and ordered by position', function () {
    Category::factory()->create(['name' => 'Bakery', 'position' => 30]);
    Category::factory()->create(['name' => 'Produce', 'position' => 10]);

    $this->getJson('/v1/categories')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Produce')
        ->assertJsonPath('data.1.name', 'Bakery');
});

test('inactive categories are hidden', function () {
    Category::factory()->create(['name' => 'Visible']);
    Category::factory()->inactive()->create(['name' => 'Hidden']);

    $this->getJson('/v1/categories')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Visible');
});

test('a category carries a count of its active products', function () {
    $category = Category::factory()->create();
    Product::factory()->count(3)->for($category)->create();
    Product::factory()->inactive()->for($category)->create();

    $this->getJson('/v1/categories')
        ->assertOk()
        ->assertJsonPath('data.0.productCount', 3);
});

test('tree mode nests children under their roots', function () {
    $produce = Category::factory()->create(['name' => 'Produce', 'position' => 10]);
    Category::factory()->for($produce, 'parent')->create(['name' => 'Fruit', 'position' => 10]);
    Category::factory()->for($produce, 'parent')->create(['name' => 'Vegetables', 'position' => 20]);

    $response = $this->getJson('/v1/categories?tree=true')->assertOk();

    expect($response->json('data'))->toHaveCount(1);

    $response->assertJsonPath('data.0.name', 'Produce')
        ->assertJsonCount(2, 'data.0.children')
        ->assertJsonPath('data.0.children.0.name', 'Fruit')
        ->assertJsonPath('data.0.children.1.name', 'Vegetables');
});

test('the flat list includes children alongside roots', function () {
    $produce = Category::factory()->create(['name' => 'Produce']);
    Category::factory()->for($produce, 'parent')->create(['name' => 'Fruit']);

    $response = $this->getJson('/v1/categories')->assertOk();

    expect($response->json('data'))->toHaveCount(2);

    $fruit = collect($response->json('data'))->firstWhere('name', 'Fruit');

    expect($fruit['parentId'])->toBe($produce->public_id);
});

test('a single category comes back with its immediate children', function () {
    $produce = Category::factory()->create(['name' => 'Produce']);
    Category::factory()->for($produce, 'parent')->create(['name' => 'Fruit']);

    $this->getJson('/v1/categories/'.$produce->public_id)
        ->assertOk()
        ->assertJsonPath('id', $produce->public_id)
        ->assertJsonPath('name', 'Produce')
        ->assertJsonCount(1, 'children')
        ->assertJsonPath('children.0.name', 'Fruit');
});

test('an unknown or inactive category is a 404', function () {
    $inactive = Category::factory()->inactive()->create();

    $this->getJson('/v1/categories/'.Str::uuid7())->assertNotFound();
    $this->getJson('/v1/categories/'.$inactive->public_id)
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');
});

// ---------------------------------------------------------------------------
// Product listing, sorting and the R-01 regression
// ---------------------------------------------------------------------------

test('cheapest-first interleaves unit and weight products by effective price', function () {
    // Design-review finding R-01. Sorting on `price` puts every weight-priced product in a
    // NULL block, so the $1.79 avocado ranked below the $5.49 bag of grapes.
    $category = Category::factory()->create();

    Product::factory()->for($category)->create(['name' => 'Grape Bag', 'price' => 5.49]);
    Product::factory()->for($category)->weight()->create([
        'name' => 'Loose Avocado',
        'price_per_kg' => 8.95,
        'average_weight_kg' => 0.200,
    ]);
    Product::factory()->for($category)->create(['name' => 'Spaghetti', 'price' => 2.19]);

    $response = $this->getJson('/v1/products?sort=price_asc')->assertOk();

    expect(array_column($response->json('data'), 'name'))
        ->toBe(['Loose Avocado', 'Spaghetti', 'Grape Bag'])
        ->and(array_column($response->json('data'), 'effectivePrice'))
        ->toBe(['1.79', '2.19', '5.49']);
});

test('most-expensive-first is the exact reverse', function () {
    Product::factory()->create(['name' => 'Cheap', 'price' => 1.00]);
    Product::factory()->weight()->create(['name' => 'Dear', 'price_per_kg' => 20.00, 'average_weight_kg' => 1.000]);

    $response = $this->getJson('/v1/products?sort=price_desc')->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe(['Dear', 'Cheap']);
});

test('every product exposes an effective price whatever its pricing shape', function () {
    Product::factory()->create(['price' => 4.29]);
    Product::factory()->weight()->create(['price_per_kg' => 1.52, 'average_weight_kg' => 1.000]);

    $response = $this->getJson('/v1/products')->assertOk();

    foreach ($response->json('data') as $product) {
        expect($product['effectivePrice'])->toBeString()->not->toBeNull();
    }
});

test('money fields are strings, never JSON numbers', function () {
    Product::factory()->create(['price' => 4.29, 'compare_at_price' => 6.49]);

    $product = $this->getJson('/v1/products')->json('data.0');

    expect($product['effectivePrice'])->toBeString()
        ->and($product['price'])->toBeString()
        ->and($product['compareAtPrice'])->toBeString();
});

test('a weight product reports pricePerKg and a null price', function () {
    Product::factory()->weight()->create(['price_per_kg' => 1.52, 'average_weight_kg' => 1.000]);

    $this->getJson('/v1/products')
        ->assertOk()
        ->assertJsonPath('data.0.soldBy', 'weight')
        ->assertJsonPath('data.0.price', null)
        ->assertJsonPath('data.0.pricePerKg', '1.52');
});

test('inactive products are never listed', function () {
    Product::factory()->create(['name' => 'Listed']);
    Product::factory()->inactive()->create(['name' => 'Unlisted']);

    $this->getJson('/v1/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Listed');
});

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

test('products can be filtered to a single category without widening to descendants', function () {
    $produce = Category::factory()->create();
    $fruit = Category::factory()->for($produce, 'parent')->create();

    Product::factory()->for($produce)->create(['name' => 'In Produce']);
    Product::factory()->for($fruit)->create(['name' => 'In Fruit']);

    $this->getJson('/v1/products?categoryId='.$produce->public_id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'In Produce');
});

test('an unknown category filter matches nothing rather than everything', function () {
    Product::factory()->count(3)->create();

    $this->getJson('/v1/products?categoryId='.Str::uuid7())
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('the inStock filter excludes anything with no available stock', function () {
    $available = Product::factory()->create(['name' => 'Available']);
    $soldOut = Product::factory()->create(['name' => 'Sold Out']);
    $reserved = Product::factory()->create(['name' => 'All Reserved']);

    Inventory::factory()->for($available)->create(['quantity_on_hand' => 10.000, 'quantity_reserved' => 0.000]);
    Inventory::factory()->for($soldOut)->outOfStock()->create();
    // Entire stock spoken for by unpaid checkouts — available, not on-hand, is what counts.
    Inventory::factory()->for($reserved)->create(['quantity_on_hand' => 5.000, 'quantity_reserved' => 5.000]);

    $this->getJson('/v1/products?inStock=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Available');
});

test('inStock is reported per product', function () {
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->outOfStock()->create();

    $this->getJson('/v1/products')
        ->assertOk()
        ->assertJsonPath('data.0.inStock', false);
});

test('a product with no inventory row reads as out of stock', function () {
    Product::factory()->create();

    $this->getJson('/v1/products')->assertOk()->assertJsonPath('data.0.inStock', false);
});

test('products can be filtered by pricing shape', function () {
    Product::factory()->create(['name' => 'By The Unit']);
    Product::factory()->weight()->create(['name' => 'By The Kilo']);

    $this->getJson('/v1/products?soldBy=weight')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'By The Kilo');
});

test('unknown filter values are rejected as validation failures', function (string $query) {
    $this->getJson('/v1/products?'.$query)
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/validation-failed');
})->with([
    'bad sort' => ['sort=cheapest'],
    'bad soldBy' => ['soldBy=barrel'],
    'limit too large' => ['limit=500'],
    'limit of zero' => ['limit=0'],
]);

// Full-text search lives in tests/Search — InnoDB only updates its FULLTEXT index at commit,
// so those tests truncate rather than transact.

// ---------------------------------------------------------------------------
// Keyset pagination
// ---------------------------------------------------------------------------

test('a page reports whether more results exist', function () {
    Product::factory()->count(5)->create();

    $this->getJson('/v1/products?limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('page.hasMore', true)
        ->assertJsonStructure(['page' => ['hasMore', 'nextCursor']]);
});

test('the last page carries no cursor', function () {
    Product::factory()->count(2)->create();

    $this->getJson('/v1/products?limit=10')
        ->assertOk()
        ->assertJsonPath('page.hasMore', false)
        ->assertJsonPath('page.nextCursor', null);
});

test('walking the cursor visits every product exactly once', function (string $sort) {
    Product::factory()->count(7)->create();

    $seen = [];
    $cursor = null;
    $pages = 0;

    do {
        $url = "/v1/products?limit=2&sort={$sort}".($cursor ? '&cursor='.urlencode($cursor) : '');
        $response = $this->getJson($url)->assertOk();

        $seen = [...$seen, ...array_column($response->json('data'), 'id')];
        $cursor = $response->json('page.nextCursor');
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($seen)->toHaveCount(7)
        ->and(array_unique($seen))->toHaveCount(7)
        ->and($pages)->toBe(4);
})->with(['price_asc', 'price_desc', 'newest', 'relevance']);

test('the cursor survives products sharing a sort value', function () {
    // Without a unique tiebreaker a keyset cursor stalls or repeats rows whose sort values
    // are equal. Every product here has the same price on purpose.
    Product::factory()->count(6)->create(['price' => 4.99]);

    $seen = [];
    $cursor = null;

    do {
        $url = '/v1/products?limit=2&sort=price_asc'.($cursor ? '&cursor='.urlencode($cursor) : '');
        $response = $this->getJson($url)->assertOk();

        $seen = [...$seen, ...array_column($response->json('data'), 'id')];
        $cursor = $response->json('page.nextCursor');
    } while ($cursor !== null);

    expect(array_unique($seen))->toHaveCount(6);
});

test('a malformed cursor is a 400 rather than a silent reset to page one', function () {
    Product::factory()->count(3)->create();

    $this->getJson('/v1/products?cursor=not-a-real-cursor')
        ->assertBadRequest()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/bad-request');
});

// ---------------------------------------------------------------------------
// Product detail and substitutes
// ---------------------------------------------------------------------------

test('the detail view carries images, availability and the category', function () {
    $category = Category::factory()->create(['name' => 'Dairy & Eggs']);
    $product = Product::factory()->for($category)->create(['name' => 'Whole Milk', 'price' => 4.29]);

    ProductImage::factory()->for($product)->primary()->create(['url' => 'https://cdn.example/milk.jpg']);
    ProductImage::factory()->for($product)->create(['url' => 'https://cdn.example/milk-2.jpg', 'position' => 1]);
    Inventory::factory()->for($product)->create(['quantity_on_hand' => 12.500, 'quantity_reserved' => 2.100]);

    $this->getJson('/v1/products/'.$product->public_id)
        ->assertOk()
        ->assertJsonPath('id', $product->public_id)
        ->assertJsonPath('name', 'Whole Milk')
        ->assertJsonPath('availableQuantity', '10.400')
        ->assertJsonPath('category.name', 'Dairy & Eggs')
        ->assertJsonCount(2, 'images')
        ->assertJsonPath('images.0.isPrimary', true)
        ->assertJsonPath('primaryImageUrl', 'https://cdn.example/milk.jpg');
});

test('a product image is addressed by a public id, not its row id', function () {
    $product = Product::factory()->create();
    ProductImage::factory()->for($product)->primary()->create();

    $imageId = $this->getJson('/v1/products/'.$product->public_id)->json('images.0.id');

    expect($imageId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

test('an unknown or inactive product is a 404', function () {
    $inactive = Product::factory()->inactive()->create();

    $this->getJson('/v1/products/'.Str::uuid7())->assertNotFound();
    $this->getJson('/v1/products/'.$inactive->public_id)->assertNotFound();
});

test('substitutes come from the same category, in stock, closest in price', function () {
    $category = Category::factory()->create();
    $other = Category::factory()->create();

    $target = Product::factory()->for($category)->create(['name' => 'Target', 'price' => 5.00]);
    $near = Product::factory()->for($category)->create(['name' => 'Near', 'price' => 5.50]);
    $far = Product::factory()->for($category)->create(['name' => 'Far', 'price' => 19.99]);
    $soldOut = Product::factory()->for($category)->create(['name' => 'Sold Out', 'price' => 5.10]);
    $elsewhere = Product::factory()->for($other)->create(['name' => 'Elsewhere', 'price' => 5.05]);

    foreach ([$target, $near, $far, $elsewhere] as $stocked) {
        Inventory::factory()->for($stocked)->create(['quantity_on_hand' => 10.000, 'quantity_reserved' => 0.000]);
    }
    Inventory::factory()->for($soldOut)->outOfStock()->create();

    $response = $this->getJson('/v1/products/'.$target->public_id.'/substitutes')->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe(['Near', 'Far']);
});

test('substitutes prefer the same pricing shape', function () {
    // Swapping a loose weight item for a pre-packed one changes what the customer is being
    // charged for in a way a like-for-like swap does not.
    $category = Category::factory()->create();

    $target = Product::factory()->for($category)->weight()
        ->create(['name' => 'Loose Target', 'price_per_kg' => 10.00, 'average_weight_kg' => 1.000]);
    $sameShape = Product::factory()->for($category)->weight()
        ->create(['name' => 'Loose Alternative', 'price_per_kg' => 12.00, 'average_weight_kg' => 1.000]);
    $otherShape = Product::factory()->for($category)->create(['name' => 'Packed Alternative', 'price' => 10.10]);

    foreach ([$target, $sameShape, $otherShape] as $product) {
        Inventory::factory()->for($product)->create(['quantity_on_hand' => 10.000, 'quantity_reserved' => 0.000]);
    }

    $response = $this->getJson('/v1/products/'.$target->public_id.'/substitutes')->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe(['Loose Alternative', 'Packed Alternative']);
});

test('a product is never its own substitute', function () {
    $category = Category::factory()->create();
    $target = Product::factory()->for($category)->create(['price' => 5.00]);
    Inventory::factory()->for($target)->create(['quantity_on_hand' => 10.000, 'quantity_reserved' => 0.000]);

    $this->getJson('/v1/products/'.$target->public_id.'/substitutes')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('the substitute limit is honoured and bounded', function () {
    $category = Category::factory()->create();
    $target = Product::factory()->for($category)->create(['price' => 5.00]);

    foreach (range(1, 8) as $index) {
        $candidate = Product::factory()->for($category)->create(['price' => 5.00 + $index]);
        Inventory::factory()->for($candidate)->create(['quantity_on_hand' => 10.000, 'quantity_reserved' => 0.000]);
    }

    $this->getJson('/v1/products/'.$target->public_id.'/substitutes')->assertJsonCount(5, 'data');
    $this->getJson('/v1/products/'.$target->public_id.'/substitutes?limit=3')->assertJsonCount(3, 'data');
    $this->getJson('/v1/products/'.$target->public_id.'/substitutes?limit=999')->assertJsonCount(8, 'data');
});

// ---------------------------------------------------------------------------
// Query cost
// ---------------------------------------------------------------------------

test('listing a page of products costs a fixed number of queries', function () {
    $category = Category::factory()->create();

    foreach (range(1, 20) as $index) {
        $product = Product::factory()->for($category)->create();
        ProductImage::factory()->for($product)->primary()->create();
        Inventory::factory()->for($product)->create();
    }

    DB::enableQueryLog();
    $this->getJson('/v1/products?limit=20')->assertOk()->assertJsonCount(20, 'data');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One for the page, one per eager-loaded relation. Not one per row.
    expect($queries)->toBeLessThanOrEqual(5);
});
