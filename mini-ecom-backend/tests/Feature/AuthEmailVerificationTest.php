<?php

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('auth');
});

function capturedVerificationCode(User $user): string
{
    $code = null;

    Notification::assertSentTo($user, EmailVerificationCodeNotification::class, function (EmailVerificationCodeNotification $notification) use (&$code) {
        $code = (string) (new ReflectionProperty($notification, 'code'))->getValue($notification);

        return true;
    });

    return $code;
}

test('registration dispatches a verification code notification without blocking the response', function () {
    Notification::fake();

    $this->postJson('/v1/auth/register', [
        'email' => 'verifyme@example.com',
        'password' => 'correct-horse-battery',
        'fullName' => 'Verify Me',
    ])->assertCreated();

    $user = User::where('email', 'verifyme@example.com')->firstOrFail();

    Notification::assertSentTo($user, EmailVerificationCodeNotification::class);
});

test('resending verification returns 204 and sends nothing when already verified', function () {
    Notification::fake();

    $user = User::factory()->create(); // factory default is already verified

    $tokens = $this->postJson('/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json();

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/email/verification-notification')
        ->assertNoContent();

    Notification::assertNothingSent();
});

test('resending verification returns 202 and sends a code notification when unverified', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $tokens = $this->postJson('/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json();

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/email/verification-notification')
        ->assertStatus(202);

    Notification::assertSentTo($user, EmailVerificationCodeNotification::class);
});

test('resending verification requires authentication', function () {
    $this->postJson('/v1/auth/email/verification-notification')
        ->assertUnauthorized();
});

test('verify email with the correct code sets email_verified_at and fires the Verified event', function () {
    Notification::fake();
    Event::fake([Verified::class]);

    $user = User::factory()->unverified()->create();
    $tokens = $this->postJson('/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json();

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/email/verification-notification')
        ->assertStatus(202);

    $code = capturedVerificationCode($user);

    $response = $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/email/verify', ['code' => $code]);

    $response->assertOk()
        ->assertJsonPath('id', $user->public_id)
        ->assertJsonPath('email', $user->email);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    Event::assertDispatched(Verified::class, fn (Verified $event) => $event->user->is($user));
});

test('verify email requires authentication', function () {
    $this->postJson('/v1/auth/email/verify', ['code' => '123456'])
        ->assertUnauthorized();
});

test('verify email with a wrong code is refused', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $tokens = $this->postJson('/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json();

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/email/verification-notification')
        ->assertStatus(202);

    $code = capturedVerificationCode($user);
    $wrongCode = $code === '000000' ? '111111' : '000000';

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/email/verify', ['code' => $wrongCode])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/validation-failed');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('verification code is burned after its durable failed-attempt budget is exhausted', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $tokens = $this->postJson('/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json();

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/email/verification-notification')
        ->assertStatus(202);

    $correctCode = capturedVerificationCode($user);
    $wrongCode = $correctCode === '000000' ? '111111' : '000000';

    // The IP limiter permits these five requests; each one increments the code-row counter,
    // which survives a hypothetical attacker switching to a new IP for the next try.
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->withToken($tokens['accessToken'])
            ->postJson('/v1/auth/email/verify', ['code' => $wrongCode])
            ->assertUnprocessable();
    }

    expect(EmailVerificationCode::where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('verify email with an expired code is refused', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $tokens = $this->postJson('/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json();

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/email/verification-notification')
        ->assertStatus(202);

    $code = capturedVerificationCode($user);

    $this->travel(11)->minutes();

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/email/verify', ['code' => $code])
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/validation-failed');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('a resend invalidates the previously issued code', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $tokens = $this->postJson('/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json();

    $this->withToken($tokens['accessToken'])->postJson('/v1/auth/email/verification-notification')->assertStatus(202);
    $oldCode = capturedVerificationCode($user);

    $this->withToken($tokens['accessToken'])->postJson('/v1/auth/email/verification-notification')->assertStatus(202);

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/email/verify', ['code' => $oldCode])
        ->assertUnprocessable();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('verify email code is single-use', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $tokens = $this->postJson('/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json();

    $this->withToken($tokens['accessToken'])->postJson('/v1/auth/email/verification-notification')->assertStatus(202);
    $code = capturedVerificationCode($user);

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/email/verify', ['code' => $code])
        ->assertOk();

    $this->withToken($tokens['accessToken'])
        ->postJson('/v1/auth/email/verify', ['code' => $code])
        ->assertUnprocessable();
});
