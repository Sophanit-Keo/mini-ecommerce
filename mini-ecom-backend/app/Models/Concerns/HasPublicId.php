<?php

namespace App\Models\Concerns;

use App\Casts\BinaryUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Addresses a model by its `public_id` (a UUIDv7 in BINARY(16)) instead of its primary key.
 *
 * The auto-increment `id` is never serialised and never appears in a URL. Exposing it leaks
 * business volume — `/orders/1041` tells a competitor roughly how many orders you have taken
 * — and makes resources trivially enumerable.
 *
 * @phpstan-require-extends Model
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('public_id'))) {
                $model->setAttribute('public_id', (string) Str::uuid7());
            }
        });
    }

    public function initializeHasPublicId(): void
    {
        $this->mergeCasts(['public_id' => BinaryUuid::class]);
    }

    /**
     * Convert a dashed UUID string to the 16 raw bytes stored in the column.
     *
     * Returns null for anything that is not a UUID, so a malformed path segment resolves to
     * "no such resource" rather than a query error.
     */
    public static function encodePublicId(string $uuid): ?string
    {
        $hex = str_replace('-', '', $uuid);

        if (strlen($hex) !== 32 || ! ctype_xdigit($hex)) {
            return null;
        }

        return hex2bin($hex);
    }

    /**
     * Eloquent does not apply attribute casts to where-clause bindings, so every lookup by
     * public id must go through this scope rather than a bare where().
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWherePublicId(Builder $query, string $uuid): Builder
    {
        $binary = static::encodePublicId($uuid);

        if ($binary === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->qualifyColumn('public_id'), $binary);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function getRouteKey(): mixed
    {
        return $this->getAttribute('public_id');
    }

    /**
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== null && $field !== 'public_id') {
            return parent::resolveRouteBinding($value, $field);
        }

        return $this->newQuery()->wherePublicId((string) $value)->first();
    }
}
