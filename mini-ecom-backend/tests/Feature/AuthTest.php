<?php

use App\Enums\UserRole;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

beforeEach(function () {
    // The auth limiter is 5/min per IP and would otherwise leak between tests.
    RateLimiter::clear('auth');
});

// ---------------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------------

test('registration creates an account and issues a token pair', function () {
    $response = $this->postJson('/v1/auth/register', [
        'email' => 'newcustomer@example.com',
        'password' => 'correct-horse-battery',
        'fullName' => 'New Customer',
        'phone' => '+14155550100',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['accessToken', 'refreshToken', 'expiresIn', 'tokenType', 'user'])
        ->assertJsonPath('tokenType', 'Bearer')
        ->assertJsonPath('expiresIn', 15 * 60)
        ->assertJsonPath('user.email', 'newcustomer@example.com')
        ->assertJsonPath('user.fullName', 'New Customer')
        ->assertJsonPath('user.role', UserRole::Customer->value);

    $user = User::where('email', 'newcustomer@example.com')->firstOrFail();

    expect(Hash::check('correct-horse-battery', $user->password_hash))->toBeTrue()
        ->and($user->refreshTokens()->count())->toBe(1);
});

test('the registration response never exposes the internal row id or password hash', function () {
    $response = $this->postJson('/v1/auth/register', [
        'email' => 'private@example.com',
        'password' => 'correct-horse-battery',
        'fullName' => 'Private Person',
    ]);

    $user = $response->json('user');

    expect($user)->toHaveKeys(['id', 'email', 'fullName', 'phone', 'role'])
        ->and($user['id'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and($user)->not->toHaveKey('password_hash')
        ->and((string) $user['id'])->not->toBe('1');
});

test('a duplicate email is refused as a conflict', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/v1/auth/register', [
        'email' => 'taken@example.com',
        'password' => 'correct-horse-battery',
        'fullName' => 'Impostor',
    ])
        ->assertConflict()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/duplicate-resource');
});

test('a soft-deleted account frees its email for re-registration', function () {
    User::factory()->create(['email' => 'returning@example.com'])->delete();

    $this->postJson('/v1/auth/register', [
        'email' => 'returning@example.com',
        'password' => 'correct-horse-battery',
        'fullName' => 'Returning Customer',
    ])->assertCreated();
});

test('registration rejects a password below the minimum length', function () {
    $this->postJson('/v1/auth/register', [
        'email' => 'weak@example.com',
        'password' => 'short',
        'fullName' => 'Weak Password',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/validation-failed')
        ->assertJsonPath('errors.0.field', 'password');
});

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------

test('login exchanges credentials for a token pair', function () {
    $user = User::factory()->create(['email' => 'alice@example.com']);

    $this->postJson('/v1/auth/login', ['email' => 'alice@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->public_id)
        ->assertJsonPath('tokenType', 'Bearer');
});

test('login never distinguishes a wrong password from a missing account', function (string $email, string $password) {
    User::factory()->create(['email' => 'alice@example.com']);

    $response = $this->postJson('/v1/auth/login', ['email' => $email, 'password' => $password]);

    $response->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/invalid-credentials')
        ->assertJsonPath('detail', 'The email address or password is incorrect.');
})->with([
    'wrong password' => ['alice@example.com', 'not-the-password'],
    'no such account' => ['nobody@example.com', 'password'],
]);

test('a suspended account cannot sign in', function () {
    User::factory()->suspended()->create(['email' => 'suspended@example.com']);

    $this->postJson('/v1/auth/login', ['email' => 'suspended@example.com', 'password' => 'password'])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/invalid-credentials');
});

test('login is throttled at five attempts per minute per IP', function () {
    User::factory()->create(['email' => 'alice@example.com']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/v1/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();
    }

    $this->postJson('/v1/auth/login', ['email' => 'alice@example.com', 'password' => 'password'])
        ->assertTooManyRequests()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/rate-limited')
        ->assertHeader('Retry-After');
});

test('rate limit headers are present on a successful call', function () {
    User::factory()->create(['email' => 'alice@example.com']);

    $this->postJson('/v1/auth/login', ['email' => 'alice@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '5')
        ->assertHeader('X-RateLimit-Remaining', '4');
});

// ---------------------------------------------------------------------------
// Refresh rotation and theft detection
// ---------------------------------------------------------------------------

test('refreshing rotates the token and revokes the one presented', function () {
    $tokens = registerCustomer($this);

    $response = $this->postJson('/v1/auth/refresh', ['refreshToken' => $tokens['refreshToken']]);

    $response->assertOk()
        ->assertJsonPath('tokenType', 'Bearer');

    expect($response->json('refreshToken'))->not->toBe($tokens['refreshToken']);

    $old = RefreshToken::where('token_hash', RefreshToken::hash($tokens['refreshToken']))->firstOrFail();
    $new = RefreshToken::where('token_hash', RefreshToken::hash($response->json('refreshToken')))->firstOrFail();

    expect($old->revoked_at)->not->toBeNull()
        ->and($old->replaced_by_id)->toBe($new->id)
        ->and($new->revoked_at)->toBeNull();
});

test('the new access token works and the rotated refresh token does not', function () {
    $tokens = registerCustomer($this);

    $rotated = $this->postJson('/v1/auth/refresh', ['refreshToken' => $tokens['refreshToken']])->json();

    $this->withToken($rotated['accessToken'])->getJson('/v1/auth/me')->assertOk();

    $this->postJson('/v1/auth/refresh', ['refreshToken' => $tokens['refreshToken']])
        ->assertUnauthorized();
});

test('replaying a rotated refresh token revokes the entire chain', function () {
    // A benign race and a stolen token are indistinguishable from the server's side, so a
    // replay is always treated as theft: every session for that user ends.
    $tokens = registerCustomer($this);
    $user = User::where('email', 'chain@example.com')->firstOrFail();

    $second = $this->postJson('/v1/auth/refresh', ['refreshToken' => $tokens['refreshToken']])->json();
    $third = $this->postJson('/v1/auth/refresh', ['refreshToken' => $second['refreshToken']])->json();

    // The thief replays the first token, which the legitimate client already rotated away.
    $this->postJson('/v1/auth/refresh', ['refreshToken' => $tokens['refreshToken']])
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/token-revoked');

    expect($user->refreshTokens()->whereNull('revoked_at')->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0);

    // The legitimate client's newest token is dead too — that is the point.
    $this->postJson('/v1/auth/refresh', ['refreshToken' => $third['refreshToken']])
        ->assertUnauthorized();

    $this->withToken($third['accessToken'])->getJson('/v1/auth/me')->assertUnauthorized();
});

test('an unknown refresh token is refused without revealing that it never existed', function () {
    $this->postJson('/v1/auth/refresh', ['refreshToken' => 'never-issued'])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/token-revoked');
});

test('an expired refresh token is refused and revoked', function () {
    $user = User::factory()->create();
    $plain = 'expired-token-plaintext';

    RefreshToken::factory()->for($user)->expired()->create([
        'token_hash' => RefreshToken::hash($plain),
    ]);

    $this->postJson('/v1/auth/refresh', ['refreshToken' => $plain])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/token-revoked');

    expect(RefreshToken::where('token_hash', RefreshToken::hash($plain))->first()->revoked_at)
        ->not->toBeNull();
});

test('only the hash of a refresh token is ever stored', function () {
    $tokens = registerCustomer($this);

    expect(RefreshToken::where('token_hash', $tokens['refreshToken'])->exists())->toBeFalse()
        ->and(RefreshToken::where('token_hash', RefreshToken::hash($tokens['refreshToken']))->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Logout and identity
// ---------------------------------------------------------------------------

test('logout revokes the refresh token and is idempotent', function () {
    $tokens = registerCustomer($this);

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/logout', ['refreshToken' => $tokens['refreshToken']])
        ->assertNoContent();

    expect(RefreshToken::where('token_hash', RefreshToken::hash($tokens['refreshToken']))->first()->revoked_at)
        ->not->toBeNull();

    // Revoking twice also returns 204 — the caller's intent is already satisfied.
    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/logout', ['refreshToken' => $tokens['refreshToken']])
        ->assertNoContent();
});

test('a revoked refresh token cannot be exchanged', function () {
    $tokens = registerCustomer($this);

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/logout', ['refreshToken' => $tokens['refreshToken']]);

    $this->postJson('/v1/auth/refresh', ['refreshToken' => $tokens['refreshToken']])
        ->assertUnauthorized();
});

test('logout requires authentication', function () {
    $this->postJson('/v1/auth/logout', ['refreshToken' => 'anything'])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/unauthorized');
});

test('the current user is returned for a valid access token', function () {
    $tokens = registerCustomer($this);

    $this->withToken($tokens['accessToken'])
        ->getJson('/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('email', 'chain@example.com')
        ->assertJsonPath('role', UserRole::Customer->value)
        ->assertJsonMissingPath('password_hash');
});

test('an expired access token is refused', function () {
    $tokens = registerCustomer($this);

    $this->travel(16)->minutes();

    $this->withToken($tokens['accessToken'])->getJson('/v1/auth/me')->assertUnauthorized();
});

test('missing and malformed credentials are both refused', function (?string $token) {
    $request = $token === null ? $this : $this->withToken($token);

    $request->getJson('/v1/auth/me')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json');
})->with([
    'no token' => [null],
    'garbage token' => ['not-a-real-token'],
]);

/**
 * @return array{accessToken: string, refreshToken: string}
 */
function registerCustomer(TestCase $test): array
{
    return $test->postJson('/v1/auth/register', [
        'email' => 'chain@example.com',
        'password' => 'correct-horse-battery',
        'fullName' => 'Chain Tester',
    ])->json();
}
