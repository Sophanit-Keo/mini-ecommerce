<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\AuthenticateUser;
use App\Actions\Auth\RegisterUser;
use App\Actions\Auth\ResendEmailVerification;
use App\Actions\Auth\ResetUserPassword;
use App\Actions\Auth\RevokeRefreshToken;
use App\Actions\Auth\RotateRefreshToken;
use App\Actions\Auth\SendPasswordResetLink;
use App\Actions\Auth\VerifyUserEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\AuthTokensResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(
        private RegisterUser $registerUser,
        private AuthenticateUser $authenticateUser,
        private RotateRefreshToken $rotateRefreshToken,
        private RevokeRefreshToken $revokeRefreshToken,
        private SendPasswordResetLink $sendPasswordResetLink,
        private ResetUserPassword $resetUserPassword,
        private ResendEmailVerification $resendEmailVerification,
        private VerifyUserEmail $verifyUserEmail,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $tokens = $this->registerUser->handle($request->validated(), $request);

        return AuthTokensResource::make($tokens)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $tokens = $this->authenticateUser->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request,
        );

        return AuthTokensResource::make($tokens)->response();
    }

    /**
     * Rotates the presented refresh token. Replaying an already-rotated one revokes the whole
     * chain — see RotateRefreshToken.
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $tokens = $this->rotateRefreshToken->handle(
            $request->string('refreshToken')->toString(),
            $request,
        );

        return AuthTokensResource::make($tokens)->response();
    }

    public function logout(RefreshTokenRequest $request): Response
    {
        $this->revokeRefreshToken->handle($request->user(), $request->string('refreshToken')->toString());

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    /**
     * Always answers 202 with the same generic body, whether or not the address has an
     * account — see SendPasswordResetLink.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->sendPasswordResetLink->handle($request->string('email')->toString());

        return response()->json([
            'message' => 'If an account exists for that email address, a reset link has been sent.',
        ], Response::HTTP_ACCEPTED);
    }

    public function resetPassword(ResetPasswordRequest $request): Response
    {
        $this->resetUserPassword->handle($request->validated());

        return response()->noContent();
    }

    /**
     * Resends the verification notification for the current user. 204 without re-sending if
     * already verified, 202 if a new notification was actually sent.
     */
    public function sendEmailVerification(Request $request): Response
    {
        $sent = $this->resendEmailVerification->handle($request->user());

        return $sent
            ? response()->noContent(Response::HTTP_ACCEPTED)
            : response()->noContent();
    }

    public function verifyEmail(VerifyEmailRequest $request): UserResource
    {
        $user = $this->verifyUserEmail->handle(
            $request->user(),
            $request->string('code')->toString(),
        );

        return UserResource::make($user);
    }
}
