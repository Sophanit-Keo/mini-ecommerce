<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\RefreshToken;
use App\Models\TelegramLinkChallenge;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function profile(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    public function updateProfile(Request $request): UserResource
    {
        $data = $request->validate(['fullName' => ['sometimes', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:32']]);
        $user = $request->user();
        $user->update(array_filter([
            'full_name' => $data['fullName'] ?? null,
            'phone' => $data['phone'] ?? null,
        ], fn ($value, $key) => array_key_exists($key === 'full_name' ? 'fullName' : 'phone', $data), ARRAY_FILTER_USE_BOTH));

        return UserResource::make($user->fresh());
    }

    public function sessions(Request $request): JsonResponse
    {
        $sessions = $request->user()->refreshTokens()->orderByDesc('id')->get()->map(fn (RefreshToken $token) => [
            'id' => $token->id,
            'issuedAt' => $token->issued_at?->toIso8601String(),
            'expiresAt' => $token->expires_at?->toIso8601String(),
            'revokedAt' => $token->revoked_at?->toIso8601String(),
            'userAgent' => $token->user_agent,
            'ipAddress' => is_string($token->ip_address) ? (inet_ntop($token->ip_address) ?: null) : null,
        ]);

        return response()->json(['data' => $sessions]);
    }

    public function logoutAll(Request $request): Response
    {
        DB::transaction(function () use ($request): void {
            RefreshToken::where('user_id', $request->user()->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $request->user()->tokens()->delete();
        });

        return response()->noContent();
    }

    public function changePassword(Request $request): Response
    {
        $data = $request->validate(['currentPassword' => ['required', 'string'], 'newPassword' => ['required', 'string', 'min:12', 'confirmed']]);
        $user = $request->user();
        if (! Hash::check($data['currentPassword'], $user->password_hash)) {
            throw ProblemException::validationFailed([['field' => 'currentPassword', 'message' => 'The current password is incorrect.']]);
        }

        DB::transaction(function () use ($user, $data): void {
            $user->update(['password_hash' => $data['newPassword']]);
            RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $user->tokens()->delete();
        });

        return response()->noContent();
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['notificationPreferences' => $request->user()->notification_preferences ?? ['emailOrderUpdates' => true, 'telegramOrderUpdates' => false]]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate(['emailOrderUpdates' => ['sometimes', 'boolean'], 'telegramOrderUpdates' => ['sometimes', 'boolean']]);
        $preferences = array_replace(['emailOrderUpdates' => true, 'telegramOrderUpdates' => false], $request->user()->notification_preferences ?? [], $data);
        $request->user()->update(['notification_preferences' => $preferences]);

        return response()->json(['notificationPreferences' => $preferences]);
    }

    public function createTelegramLinkChallenge(Request $request): JsonResponse
    {
        $code = Str::upper(Str::random(8));
        TelegramLinkChallenge::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(10), 'consumed_at' => null],
        );

        return response()->json(['code' => $code, 'expiresAt' => now()->addMinutes(10)->toIso8601String(), 'instruction' => 'Send /link '.$code.' to the official Grocerly Telegram bot.']);
    }

    public function close(Request $request): Response
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        if (! Hash::check($data['password'], $user->password_hash)) {
            throw ProblemException::validationFailed([['field' => 'password', 'message' => 'The password is incorrect.']]);
        }
        if ($user->orders()->whereIn('status', ['pending_payment', 'confirmed', 'picking', 'packed', 'out_for_delivery'])->exists()) {
            throw ProblemException::badRequest('An account with an active order cannot be closed.');
        }

        DB::transaction(function () use ($user): void {
            RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->noContent();
    }
}
