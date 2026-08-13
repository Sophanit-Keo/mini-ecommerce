<?php

namespace App\Actions\Admin;

use App\Models\AdminAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Records only caller-provided sanitized snapshots. Callers must never pass credentials, raw
 * provider responses, tokens, password fields, or unrelated customer data into this boundary.
 */
final class RecordAdminAuditEvent
{
    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    public function handle(
        User $actor,
        string $action,
        Model $entity,
        ?array $before,
        ?array $after,
        Request $request,
    ): void {
        $requestId = $request->header('X-Request-Id');
        $publicId = $entity->getRawOriginal('public_id');

        AdminAuditEvent::create([
            'actor_id' => $actor->id,
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_public_id' => is_string($publicId) && strlen($publicId) === 16 ? $publicId : null,
            'before' => $before,
            'after' => $after,
            'request_id' => is_string($requestId) ? mb_substr($requestId, 0, 64) : null,
        ]);
    }
}
