<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Exceptions\ProblemException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a request whose account is no longer allowed to act, on every authenticated route.
 *
 * Account status used to be checked in exactly one place — `AuthenticateUser`, at login — which
 * meant suspending an account did nothing to the sessions it already had. The suspended user's
 * access token stayed valid for up to its full 15 minutes, and (worse) `RotateRefreshToken`
 * never looked at status at all, so that user could keep exchanging refresh tokens indefinitely
 * and never lose access. Suspension was effectively advisory.
 *
 * This runs after `auth:sanctum`, so `$request->user()` is already resolved. A soft-deleted
 * user cannot reach here at all — Sanctum's user resolution applies the `SoftDeletes` global
 * scope — so the only case left to catch is a status change.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Sanctum loads the tokenable model from the database for a real Bearer request, but
        // cacheable guards, long-lived workers, and test guard helpers can hand us a model that
        // was resolved just before an administrator suspended it. Refreshing this one small row
        // is the security boundary: session validity must follow the current database state,
        // not an earlier PHP object. `fresh()` honours SoftDeletes, so a deleted account also
        // resolves to null and is refused here.
        $currentUser = $user?->fresh();

        if ($user !== null && ($currentUser === null || $currentUser->status !== UserStatus::Active)) {
            // The session is destroyed rather than merely refused: a suspended account should
            // not be left holding a credential that starts working again the moment the
            // suspension is lifted, and re-authenticating is the correct way back in.
            $user->tokens()->delete();
            $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);

            throw ProblemException::accountSuspended();
        }

        return $next($request);
    }
}
