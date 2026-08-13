<?php

namespace App\Models;

use App\Casts\BinaryUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'action', 'entity_type', 'entity_public_id', 'before', 'after', 'request_id'])]
class AdminAuditEvent extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entity_public_id' => BinaryUuid::class,
            'before' => 'array',
            'after' => 'array',
        ];
    }
}
