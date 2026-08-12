<?php

use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * These assertions intentionally check headers on both a normal controller response and a
 * framework-rendered error. The latter is easy to miss because an exception may escape route
 * middleware before that middleware has had a chance to decorate the response.
 */
test('authentication responses are hardened and never cache bearer tokens', function () {
    $user = User::factory()->create(['email' => 'headers@example.com']);

    $this->postJson('/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
        ->assertHeader('Pragma', 'no-cache');
});

test('framework-rendered API errors also receive security headers', function () {
    $this->getJson('/v1/not-a-real-endpoint')
        ->assertNotFound()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
});

test('CORS allows only configured browser origins', function () {
    // Two entries deliberately exercise the dynamic allowlist branch. With exactly one allowed
    // origin, the CORS library correctly emits that fixed value for every request; browsers
    // still reject it for a different Origin, but the dynamic path is stricter to regression-test.
    Config::set('cors.allowed_origins', ['https://shop.example.test', 'https://admin.example.test']);

    $this->withHeader('Origin', 'https://shop.example.test')
        ->getJson('/v1/products')
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://shop.example.test')
        ->assertHeader('Vary', 'Origin');

    // `withHeader` accumulates default test headers. Browser requests contain exactly one
    // Origin header, so clear the prior request's header before modelling a different site.
    $untrusted = $this->flushHeaders()
        ->withHeader('Origin', 'https://untrusted.example.test')
        ->getJson('/v1/products');

    $untrusted->assertOk()
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('an oversized JSON document is rejected before validation and remains hardened', function () {
    $response = $this->call(
        'POST',
        '/v1/auth/login',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['email' => 'headers@example.com', 'password' => str_repeat('x', 70000)]),
    );

    $response->assertStatus(413)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/payload-too-large');
});
