<?php

namespace Database\Seeders\Concerns;

/**
 * Reproduces the deterministic `public_id` scheme used by `db/seed.sql`.
 *
 * Seeded rows get a predictable UUIDv7-shaped identifier — `0192f3a1-0000-7000-800T-…id`
 * where T is a per-table digit — so the worked examples in `docs/api-design.md` (for
 * instance product `0192f3a1-0000-7000-8004-000000000007`) address real rows and can be
 * pasted straight into a request.
 */
trait SeedsPublicIds
{
    private function publicId(int $table, int $id): string
    {
        return sprintf('0192f3a1-0000-7000-800%d-%012x', $table, $id);
    }
}
