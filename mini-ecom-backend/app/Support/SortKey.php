<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * One component of a keyset sort: the SQL to order by, and how to read the same value back
 * off a model so it can be written into the cursor.
 *
 * `expression` is raw SQL rather than a column name because relevance ranking sorts on
 * `MATCH(...) AGAINST(...)`, which has to be repeated verbatim in the keyset predicate —
 * MySQL does not allow a select alias in a WHERE clause.
 */
final readonly class SortKey
{
    /**
     * @param  'asc'|'desc'  $direction
     * @param  Closure(Model): (string|int|float|null)  $value
     * @param  array<int, mixed>  $bindings
     */
    public function __construct(
        public string $expression,
        public string $direction,
        public Closure $value,
        public array $bindings = [],
    ) {}

    /**
     * The comparison that moves past a row: later rows are greater under `asc`, smaller
     * under `desc`.
     */
    public function operator(): string
    {
        return $this->direction === 'asc' ? '>' : '<';
    }
}
