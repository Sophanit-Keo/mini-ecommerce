<?php

namespace App\Http\Resources;

use App\Models\AdminAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AdminAuditEvent */
class AdminAuditEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'entityType' => $this->entity_type,
            'entityId' => $this->entity_public_id === null ? null : $this->entity_public_id,
            'before' => $this->before,
            'after' => $this->after,
            'actorId' => $this->whenLoaded('actor', fn () => $this->actor?->public_id),
            'requestId' => $this->request_id,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
