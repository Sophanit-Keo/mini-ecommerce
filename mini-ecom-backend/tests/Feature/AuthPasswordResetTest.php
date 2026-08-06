<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('auth');
});

test('forgot password always answers 202 whether or not the account exists', function () {
    $user = User::factory()->create(['email' => 'known@example.com']);

    $known = $this->postJson('/v1/auth/password/forgot', ['email' => 'known@example.com']);
    $unknown = $this->postJson('/v1/auth/password/forgot', ['email' => 'nobody@example.com']);

    $known->assertStatus(202);
    $unknown->assertStatus(202);

    expect($known->json())->toBe($unknown->json())
        ->and($known->headers->get('Content-Type'))->toBe($unknown->headers->get('Content-Type'));
});

test('forgot password sends a reset notification only for a real account', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'known@example.com']);

    $this->postJson('/v1/auth/password/forgot', ['email' => 'known@example.com'])->assertStatus(202);
    $this->postJson('/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertStatus(202);

    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

test('reset password changes the password and old credentials stop working', function () {
    $user = User::factory()->create(['email' => 'reset@example.com']);
    $token = Password::createToken($user);

    $this->postJson('/v1/auth/password/reset', [
        'email' => 'reset@example.com',
        'token' => $token,
        'password' => 'new-correct-horse-battery',
    ])->assertNoContent();

    $user->refresh();

    expect(Hash::check('new-correct-horse-battery', $user->password_hash))->toBeTrue()
        ->and(Hash::check('password', $user->password_hash))->toBeFalse();

    $this->postJson('/v1/auth/login', ['email' => 'reset@example.com', 'password' => 'password'])
        ->assertUnauthorized();

    $this->postJson('/v1/auth/login', ['email' => 'reset@example.com', 'password' => 'new-correct-horse-battery'])
        ->assertOk();
});

test('reset password revokes every existing refresh token and access token', function () {
    $registration = $this->postJson('/v1/auth/register', [
        'email' => 'sessions@example.com',
        'password' => 'correct-horse-battery',
        'fullName' => 'Sessions Tester',
    ])->json();

    $user = User::where('email', 'sessions@example.com')->firstOrFail();
    $token = Password::createToken($user);

    $this->postJson('/v1/auth/password/reset', [
        'email' => 'sessions@example.com',
        'token' => $token,
        'password' => 'new-correct-horse-battery',
    ])->assertNoContent();

    expect($user->refreshTokens()->whereNull('revoked_at')->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0);

    $this->postJson('/v1/auth/refresh', ['refreshToken' => $registration['refreshToken']])
        ->assertUnauthorized();

    $this->withToken($registration['accessToken'])->getJson('/v1/auth/me')->assertUnauthorized();
});

test('reset password with a bad or expired token is refused without saying which was wrong', function () {
    $user = User::factory()->create(['email' => 'bad-token@example.com']);

    $response = $this->postJson('/v1/auth/password/reset', [
        'email' => 'bad-token@example.com',
        'token' => 'not-a-real-token',
        'password' => 'new-correct-horse-battery',
    ]);

    $response->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/validation-failed');

    expect(Hash::check('password', $user->fresh()->password_hash))->toBeTrue();
});

test('reset password for an unknown email is refused the same way as a bad token', function () {
    $response = $this->postJson('/v1/auth/password/reset', [
        'email' => 'nobody@example.com',
        'token' => 'whatever',
        'password' => 'new-correct-horse-battery',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/validation-failed');
});
