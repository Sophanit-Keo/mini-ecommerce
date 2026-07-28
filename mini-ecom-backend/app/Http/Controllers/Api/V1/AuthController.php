<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\AuthenticateUser;
use App\Actions\Auth\RegisterUser;
use App\Actions\Auth\RevokeRefreshToken;
use App\Actions\Auth\RotateRefreshToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\RegisterRequest;
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
}
