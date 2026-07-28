<?php

use App\Models\Category;
use App\Models\Product;

/*
| These tests truncate rather than transact — see tests/Pest.php.
|
| InnoDB updates its FULLTEXT index at commit, so a fixture inserted inside an open
| transaction is invisible to MATCH ... AGAINST. Under RefreshDatabase every one of these
| would report "no results" while the rows plainly exist.
*/

function makeBakery(): Category
{
    return Category::factory()->create(['name' => 'Bakery', 'slug' => 'bakery-'.uniqid()]);
}

test('search matches across name, brand and description', function (string $term, string $expected) {
    $category = makeBakery();

    Product::factory()->for($category)->create([
        'name' => 'Sourdough Loaf',
        'brand' => 'Corner Bake',
        'description' => 'Naturally leavened, baked this morning.',
    ]);
    Product::factory()->for($category)->create([
        'name' => 'Basmati Rice',
        'brand' => 'Golden Field',
        'description' => 'Aged long-grain basmati.',
    ]);

    $response = $this->getJson('/v1/products?q='.urlencode($term))->assertOk();

    expect(array_column($response->json('data'), 'name'))->toContain($expected);
})->with([
    'by name' => ['sourdough', 'Sourdough Loaf'],
    'by brand' => ['Corner', 'Sourdough Loaf'],
    'by description' => ['leavened', 'Sourdough Loaf'],
    'another product by name' => ['basmati', 'Basmati Rice'],
]);

test('search is case-insensitive', function () {
    Product::factory()->for(makeBakery())->create(['name' => 'Sourdough Loaf']);

    $this->getJson('/v1/products?q=SOURDOUGH')->assertOk()->assertJsonCount(1, 'data');
});

test('a search matching nothing returns an empty page rather than an error', function () {
    Product::factory()->for(makeBakery())->create(['name' => 'Sourdough Loaf']);

    $this->getJson('/v1/products?q=helicopter')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('page.hasMore', false)
        ->assertJsonPath('page.nextCursor', null);
});

test('a query below the full-text token size returns nothing rather than erroring', function () {
    // innodb_ft_min_token_size defaults to 3, so two-character terms are simply ignored.
    Product::factory()->for(makeBakery())->create(['name' => 'Oat Milk']);

    $this->getJson('/v1/products?q=oa')->assertOk()->assertJsonCount(0, 'data');
});

test('search only returns active products', function () {
    $category = makeBakery();

    Product::factory()->for($category)->create(['name' => 'Sourdough Loaf']);
    Product::factory()->for($category)->inactive()->create(['name' => 'Sourdough Discontinued']);

    $this->getJson('/v1/products?q=sourdough')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Sourdough Loaf');
});

test('search combines with a category filter', function () {
    $wanted = makeBakery();
    $other = makeBakery();

    Product::factory()->for($wanted)->create(['name' => 'Sourdough Loaf']);
    Product::factory()->for($other)->create(['name' => 'Sourdough Starter']);

    $this->getJson('/v1/products?q=sourdough&categoryId='.$wanted->public_id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Sourdough Loaf');
});

test('search combines with an explicit price sort', function () {
    $category = makeBakery();

    Product::factory()->for($category)->create(['name' => 'Sourdough Large', 'price' => 8.99]);
    Product::factory()->for($category)->create(['name' => 'Sourdough Small', 'price' => 4.99]);

    $response = $this->getJson('/v1/products?q=sourdough&sort=price_asc')->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe(['Sourdough Small', 'Sourdough Large']);
});

test('a relevance-sorted search paginates without repeating rows', function () {
    // Relevance is not a plain column, so the cursor has to carry the ranking of the last row
    // and the keyset predicate has to repeat the MATCH expression verbatim.
    $category = makeBakery();

    foreach (range(1, 5) as $index) {
        Product::factory()->for($category)->create([
            'name' => "Sourdough Loaf {$index}",
            'description' => 'Naturally leavened sourdough bread.',
        ]);
    }

    $seen = [];
    $cursor = null;
    $pages = 0;

    // Bounded: a cursor that fails to advance would otherwise walk until the rate limiter
    // cuts in, and a 429 says nothing about what actually broke.
    do {
        $url = '/v1/products?q=sourdough&limit=2'.($cursor ? '&cursor='.urlencode($cursor) : '');
        $response = $this->getJson($url)->assertOk();

        $seen = [...$seen, ...array_column($response->json('data'), 'id')];
        $cursor = $response->json('page.nextCursor');
        $pages++;
    } while ($cursor !== null && $pages < 6);

    expect($pages)->toBe(3)
        ->and($seen)->toHaveCount(5)
        ->and(array_unique($seen))->toHaveCount(5);
});

test('a more relevant product outranks a less relevant one', function () {
    $category = makeBakery();

    Product::factory()->for($category)->create([
        'name' => 'Sourdough Sourdough Sourdough',
        'description' => 'Sourdough through and through.',
    ]);
    Product::factory()->for($category)->create([
        'name' => 'Rye Bread',
        'description' => 'Contains a little sourdough starter.',
    ]);

    $response = $this->getJson('/v1/products?q=sourdough')->assertOk();

    expect($response->json('data.0.name'))->toBe('Sourdough Sourdough Sourdough');
});
