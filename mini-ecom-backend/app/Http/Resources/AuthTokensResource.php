<?php

namespace App\Http\Resources;

use App\Support\TokenPair;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read TokenPair $resource
 */
class AuthTokensResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'accessToken' => $this->resource->accessToken,
            'refreshToken' => $this->resource->refreshToken,
            'expiresIn' => $this->resource->expiresIn,
            'tokenType' => 'Bearer',
            'user' => new UserResource($this->resource->user),
        ];
    }
}
