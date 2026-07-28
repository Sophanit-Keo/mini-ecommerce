<?php

namespace App\Support;

use App\Exceptions\ProblemException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Keyset (cursor) pagination.
 *
 * Customer-facing lists page this way rather than by offset. `OFFSET 5000` makes MySQL read
 * and discard 5,000 rows on every request, so page 200 is dramatically slower than page 1. A
 * keyset cursor is a `WHERE (sort_key, id) > (?, ?)` predicate that uses the index directly
 * and costs the same on every page. It also cannot skip or duplicate rows when the
 * underlying data shifts between requests — offset paging can and does.
 *
 * The cursor is opaque: clients must not decode or construct one.
 *
 * @template TModel of Model
 */
final readonly class CursorPage
{
    /**
     * @param  Collection<int, TModel>  $items
     */
    public function __construct(
        public Collection $items,
        public bool $hasMore,
        public ?string $nextCursor,
    ) {}

    /**
     * @param  Builder<TModel>  $query
     * @param  array<int, SortKey>  $keys  most significant first; the last must be unique
     * @return self<TModel>
     */
    public static function build(Builder $query, array $keys, int $limit, ?string $cursor): self
    {
        if ($cursor !== null) {
            self::applyCursor($query, $keys, self::decode($cursor, count($keys)));
        }

        foreach ($keys as $key) {
            $query->orderByRaw("{$key->expression} {$key->direction}", $key->bindings);
        }

        // One extra row answers "is there another page?" without a second COUNT query.
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit);

        return new self(
            items: $items,
            hasMore: $hasMore,
            nextCursor: $hasMore && $items->isNotEmpty() ? self::encode($keys, $items->last()) : null,
        );
    }

    /**
     * The `page` object of the response envelope.
     *
     * @return array{hasMore: bool, nextCursor: string|null}
     */
    public function toPageInfo(): array
    {
        return [
            'hasMore' => $this->hasMore,
            'nextCursor' => $this->nextCursor,
        ];
    }

    /**
     * Lexicographic "strictly after this row", expanded into the form MySQL can satisfy
     * from an index:
     *
     *     (a > ?)
     *     OR (a = ? AND b > ?)
     *     OR (a = ? AND b = ? AND c > ?)
     *
     * A plain row-value comparison would read more naturally but does not use a composite
     * index on every MySQL version, which would defeat the point of paging this way.
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, SortKey>  $keys
     * @param  array<int, mixed>  $values
     */
    private static function applyCursor(Builder $query, array $keys, array $values): void
    {
        $query->where(function (Builder $outer) use ($keys, $values) {
            foreach ($keys as $index => $key) {
                $outer->orWhere(function (Builder $branch) use ($keys, $values, $index) {
                    for ($previous = 0; $previous < $index; $previous++) {
                        $branch->whereRaw(
                            "{$keys[$previous]->expression} <=> ?",
                            [...$keys[$previous]->bindings, $values[$previous]],
                        );
                    }

                    $branch->whereRaw(
                        "{$keys[$index]->expression} {$keys[$index]->operator()} ?",
                        [...$keys[$index]->bindings, $values[$index]],
                    );
                });
            }
        });
    }

    /**
     * @param  array<int, SortKey>  $keys
     */
    private static function encode(array $keys, Model $model): string
    {
        $values = array_map(fn (SortKey $key) => ($key->value)($model), $keys);

        return rtrim(strtr(base64_encode((string) json_encode($values)), '+/', '-_'), '=');
    }

    /**
     * @return array<int, mixed>
     */
    private static function decode(string $cursor, int $expectedParts): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), strict: false);
        $values = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($values) || count($values) !== $expectedParts) {
            // The cursor is opaque and comes only from a previous response. A malformed one
            // is a client bug, not a filter — silently ignoring it would hand back page one
            // and look like an infinite list.
            throw ProblemException::badRequest('The cursor is not valid. Use the value from the previous response.');
        }

        return array_values($values);
    }
}
