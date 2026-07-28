<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Translates between a BINARY(16) column and a dashed UUID string.
 *
 * The spec stores every `public_id` as a raw 16-byte UUIDv7 rather than a 36-character
 * string: it halves the index size and keeps the natural time ordering of UUIDv7 intact,
 * which matters because these columns carry UNIQUE indexes on every table.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class BinaryUuid implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // MySQL may hand back a stream resource for BINARY columns depending on the driver.
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (strlen((string) $value) !== 16) {
            throw new InvalidArgumentException("Column [{$key}] does not hold 16 raw UUID bytes.");
        }

        $hex = bin2hex((string) $value);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Already raw bytes — a value round-tripping through a query builder, for instance.
        if (is_string($value) && strlen($value) === 16 && ! self::looksLikeUuid($value)) {
            return $value;
        }

        $hex = str_replace('-', '', (string) $value);

        if (strlen($hex) !== 32 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException("Value for [{$key}] is not a valid UUID.");
        }

        return hex2bin($hex);
    }

    private static function looksLikeUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
