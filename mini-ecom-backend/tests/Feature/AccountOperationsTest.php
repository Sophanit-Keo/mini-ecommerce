<?php

use App\Models\Order;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->user = User::factory()->create(['password_hash' => 'Current-password-123']);
    $this->actingAs($this->user, 'sanctum');
});

test('customer profile and notification preferences are customer-scoped', function () {
    $this->patchJson('/v1/account/profile', ['fullName' => 'Updated Customer', 'phone' => '+85512345678'])
        ->assertOk()->assertJsonPath('fullName', 'Updated Customer');

    $this->patchJson('/v1/account/notification-preferences', ['emailOrderUpdates' => false, 'telegramOrderUpdates' => true])
        ->assertOk()->assertJsonPath('notificationPreferences.emailOrderUpdates', false)
        ->assertJsonPath('notificationPreferences.telegramOrderUpdates', true);

    $this->getJson('/v1/account/notification-preferences')
        ->assertOk()->assertJsonPath('notificationPreferences.telegramOrderUpdates', true);
});

test('password changes and logout-all revoke every refresh session', function () {
    $tokens = RefreshToken::factory()->count(2)->for($this->user)->create(['revoked_at' => null]);

    $this->getJson('/v1/account/sessions')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.tokenHash');

    $this->postJson('/v1/account/change-password', [
        'currentPassword' => 'Current-password-123',
        'newPassword' => 'Replacement-password-456',
        'newPassword_confirmation' => 'Replacement-password-456',
    ])->assertNoContent();

    expect(Hash::check('Replacement-password-456', $this->user->fresh()->password_hash))->toBeTrue()
        ->and($tokens->every(fn (RefreshToken $token) => $token->fresh()->revoked_at !== null))->toBeTrue();
});

test('logout-all and account close require safe customer state', function () {
    RefreshToken::factory()->for($this->user)->create(['revoked_at' => null]);
    $this->postJson('/v1/account/logout-all')->assertNoContent();
    expect($this->user->refreshTokens()->whereNull('revoked_at')->exists())->toBeFalse();

    Order::factory()->for($this->user)->create(['status' => 'confirmed']);
    $this->postJson('/v1/account/close', ['password' => 'Current-password-123'])->assertBadRequest();

    $this->user->orders()->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'Test closure']);
    $this->postJson('/v1/account/close', ['password' => 'Current-password-123'])->assertNoContent();
    expect($this->user->fresh()->trashed())->toBeTrue();
});
