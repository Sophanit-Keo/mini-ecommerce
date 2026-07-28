<?php

namespace App\Actions\Auth;

use App\Enums\UserStatus;
use App\Exceptions\ProblemException;
use App\Models\User;
use App\Support\TokenPair;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthenticateUser
{
    public function __construct(private IssueTokenPair $issueTokenPair) {}

    public function handle(string $email, string $password, Request $request): TokenPair
    {
        $user = User::where('email', $email)->first();

        // The hash is computed even when no such user exists, so a missing account and a wrong
        // password take the same time. Without this the response latency is an enumeration
        // oracle regardless of how carefully the message is worded.
        $passwordMatches = $user !== null
            ? Hash::check($password, $user->password_hash)
            : Hash::check($password, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');

        if ($user === null || ! $passwordMatches || $user->status === UserStatus::Suspended) {
            throw ProblemException::invalidCredentials();
        }

        return $this->issueTokenPair->handle($user, $request);
    }
}
