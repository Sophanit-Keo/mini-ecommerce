<?php

namespace App\Actions\Auth;

use App\Enums\UserStatus;
use App\Exceptions\ProblemException;
use App\Models\RefreshToken;
use App\Models\User;
use App\Support\TokenPair;
use App\Support\TokenRotationOutcome;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Exchanges a refresh token for a new pair, revoking the one presented.
 *
 * Refresh tokens are single-use. Presenting one that has already been rotated means one of
 * two things: a benign race, or a stolen token being replayed. The two are indistinguishable
 * from the server's side, so this treats it as theft — the entire chain for that user is
 * revoked and every device must sign in again.
 *
 * The transaction below only *classifies* the token and performs the happy-path rotation. It
 * deliberately never throws: rejecting a token also has to write (revoking the replayed
 * chain, or marking an expired token dead), and throwing mid-transaction would roll those
 * writes straight back — leaving a detected theft with no effect whatsoever.
 *
 * Account status is re-checked here on every rotation. It used to be checked only at login,
 * which meant suspending an account did nothing to the sessions it already had: the holder of a
 * refresh token could keep exchanging it for fresh access tokens forever and never notice they
 * had been suspended. The refresh endpoint is the one place every long-lived session must pass
 * through, so it is the right place to re-assert the decision.
 */
class RotateRefreshToken
{
    public function __construct(private IssueTokenPair $issueTokenPair) {}

    public function handle(string $plainToken, Request $request): TokenPair
    {
        [$outcome, $userId, $tokenId, $tokens] = $this->classify($plainToken, $request);

        return match ($outcome) {
            TokenRotationOutcome::Rotated => $tokens,

            // Replay of an already-rotated token. Kill everything.
            TokenRotationOutcome::Replayed => throw $this->revokeChainAndFail($userId),

            TokenRotationOutcome::Expired => throw $this->revokeSingleAndFail($tokenId),

            // Genuine token, account no longer permitted to act. Every session is killed so
            // the credential cannot spring back to life if the suspension is lifted.
            TokenRotationOutcome::Suspended => throw $this->suspendAndFail($userId),

            // An unknown token gets the same answer as a revoked one. Distinguishing them
            // would confirm which strings had ever been valid.
            TokenRotationOutcome::Unknown => throw ProblemException::tokenRevoked(),
        };
    }

    /**
     * @return array{TokenRotationOutcome, int|null, int|null, TokenPair|null}
     */
    private function classify(string $plainToken, Request $request): array
    {
        return DB::transaction(function () use ($plainToken, $request) {
            // Locked so two concurrent refreshes of the same token cannot both rotate it —
            // the loser wakes to find it revoked and is correctly treated as a replay.
            $token = RefreshToken::with('user')
                ->where('token_hash', RefreshToken::hash($plainToken))
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return [TokenRotationOutcome::Unknown, null, null, null];
            }

            if ($token->revoked_at !== null) {
                return [TokenRotationOutcome::Replayed, $token->user_id, $token->id, null];
            }

            if ($token->expires_at->isPast()) {
                return [TokenRotationOutcome::Expired, $token->user_id, $token->id, null];
            }

            // `with('user')` applies the SoftDeletes global scope, so a deleted user resolves
            // to null here and is treated exactly like a suspended one.
            if ($token->user === null || $token->user->status !== UserStatus::Active) {
                return [TokenRotationOutcome::Suspended, $token->user_id, $token->id, null];
            }

            return [
                TokenRotationOutcome::Rotated,
                $token->user_id,
                $token->id,
                $this->issueTokenPair->handle($token->user, $request, replacing: $token),
            ];
        });
    }

    /**
     * Kill every session the user has: outstanding refresh tokens, and the access tokens
     * already issued from them.
     */
    private function revokeChainAndFail(int $userId): ProblemException
    {
        RefreshToken::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        User::find($userId)?->tokens()->delete();

        return ProblemException::tokenRevoked();
    }

    private function revokeSingleAndFail(int $tokenId): ProblemException
    {
        RefreshToken::whereKey($tokenId)->update(['revoked_at' => now()]);

        return ProblemException::tokenRevoked();
    }

    /**
     * Same teardown as a detected replay — every credential the account holds is destroyed —
     * but a different answer to the client, so it stops retrying instead of looping on 401.
     */
    private function suspendAndFail(int $userId): ProblemException
    {
        RefreshToken::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        User::withTrashed()->find($userId)?->tokens()->delete();

        return ProblemException::accountSuspended();
    }
}
