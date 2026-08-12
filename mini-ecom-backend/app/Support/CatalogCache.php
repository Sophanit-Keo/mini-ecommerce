<?php

namespace App\Support;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Short-lived caching and conditional GET support for public catalogue resources.
 *
 * Catalogue reads are the highest-volume endpoints in a grocery API. They are public, have no
 * user-specific state, and the underlying data changes far less often than it is read, which
 * makes a small shared cache the highest-value performance optimization available. The cached
 * value is a resolved array rather than an Eloquent model or response object: it is safe for
 * every cache driver (database, Redis, file) and never resurrects a stale model relationship.
 *
 * `bust()` increments a tiny namespace version. No cache-driver tags are used because the
 * repository's database cache driver does not support them; versioning works identically on
 * database cache locally and Redis in production. Old values expire naturally after the TTL.
 */
final class CatalogCache
{
    private const VERSION_KEY = 'catalog:version';

    /**
     * Cache a payload under the current catalogue namespace.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public static function remember(string $resource, array $parameters, Closure $callback): mixed
    {
        $ttl = (int) config('api.catalog_cache_ttl');

        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember(
            self::key($resource, $parameters),
            now()->addSeconds($ttl),
            $callback,
        );
    }

    /**
     * Return a cacheable JSON response, or a 304 when the caller already holds this exact body.
     *
     * ETags avoid transferring and decoding a product page that has not changed. They are a
     * bandwidth optimization, not an authorization mechanism, which is why this helper is only
     * used for public GET endpoints.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function response(Request $request, array $payload): JsonResponse|Response
    {
        $encoded = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $etag = '"'.hash('sha256', $encoded).'"';
        $cacheControl = self::cacheControl();

        if (in_array($etag, $request->getETags(), true)) {
            return response('', Response::HTTP_NOT_MODIFIED, [
                'ETag' => $etag,
                'Cache-Control' => $cacheControl,
            ]);
        }

        return response()
            ->json($payload)
            ->header('ETag', $etag)
            ->header('Cache-Control', $cacheControl);
    }

    /**
     * Invalidate every public catalogue response immediately after a product mutation.
     */
    public static function bust(): void
    {
        // Some stores increment a missing key, others return false. Seed it first so this works
        // consistently with the database cache driver used by default and Redis in production.
        Cache::add(self::VERSION_KEY, 1);
        Cache::increment(self::VERSION_KEY);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private static function key(string $resource, array $parameters): string
    {
        $parameters = self::sortRecursively($parameters);
        $version = (int) Cache::get(self::VERSION_KEY, 1);

        return "catalog:v{$version}:{$resource}:".hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
    }

    private static function cacheControl(): string
    {
        $ttl = max(0, (int) config('api.catalog_cache_ttl'));

        // `s-maxage` gives a CDN the same brief cache lifetime while stale-while-revalidate
        // keeps an occasional slow origin request from becoming customer-visible latency.
        return "public, max-age={$ttl}, s-maxage={$ttl}, stale-while-revalidate=30";
    }

    /**
     * Canonicalize query parameters so `?limit=24&sort=newest` and the same parameters in the
     * reverse order land on one cache key rather than duplicating a cache entry.
     */
    private static function sortRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursively($item);
            }
        }

        ksort($value);

        return $value;
    }
}
