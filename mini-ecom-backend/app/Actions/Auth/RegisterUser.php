<?php

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ProblemException;
use App\Models\User;
use App\Support\TokenPair;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * Creates a customer account and signs them straight in.
 *
 * A duplicate email answers 409, which is an account-enumeration disclosure. The trade is
 * deliberate — always answering 201 and emailing "someone tried to register with your
 * address" is more private but materially worse for the common case of a customer who
 * forgot they had an account. Throttling (5/min per IP) keeps bulk enumeration impractical.
 */
class RegisterUser
{
    public function __construct(private IssueTokenPair $issueTokenPair) {}

    /**
     * @param  array{email: string, password: string, fullName: string, phone?: string|null}  $data
     */
    public function handle(array $data, Request $request): TokenPair
    {
        try {
            $user = User::create([
                'email' => $data['email'],
                'password_hash' => $data['password'],
                'full_name' => $data['fullName'],
                'phone' => $data['phone'] ?? null,
                'role' => UserRole::Customer,
                'status' => UserStatus::Active,
            ]);
        } catch (UniqueConstraintViolationException) {
            // uq_users_email_active is the arbiter, not a prior existence check — the check
            // loses the race when two registrations for the same address arrive together.
            throw ProblemException::duplicateResource('An account already exists for that email address.');
        }

        // Fired after the response is sent, so a slow mail transport never delays account
        // creation. This is a side effect of registration, not part of its contract.
        dispatch(function () use ($user): void {
            $user->sendEmailVerificationNotification();
        })->afterResponse();

        return $this->issueTokenPair->handle($user, $request);
    }
}
