<?php

namespace App\Http\Middleware;

use App\Exceptions\ProblemException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an oversized request body before anything tries to parse it.
 *
 * Every write endpoint in this API takes a small, flat JSON document — the largest is a product
 * PATCH with a description. Nothing legitimately sends more than a few kilobytes. Without a
 * limit, a single request can hand PHP a multi-megabyte JSON document to decode into an array,
 * and `post_max_size` (8 MB by default) is the only thing between that and the process; on a
 * serverless deployment with a small memory ceiling a handful of concurrent requests like that
 * is enough to exhaust it.
 *
 * The check reads `Content-Length` rather than the body, so an oversized request costs nothing
 * to reject. A chunked request without `Content-Length` falls through to the body length, which
 * is already in memory by then but still worth refusing before validation and hydration run.
 */
class LimitRequestSize
{
    /**
     * 64 KB. Roughly two orders of magnitude above the largest legitimate payload.
     */
    private const MAX_BYTES = 65536;

    public function handle(Request $request, Closure $next, int $maxBytes = self::MAX_BYTES): Response
    {
        $declared = $request->headers->get('Content-Length');

        $size = $declared !== null
            ? (int) $declared
            : strlen((string) $request->getContent());

        if ($size > $maxBytes) {
            throw new ProblemException(
                'payload-too-large',
                'Request body too large',
                413,
                "The request body may not exceed {$maxBytes} bytes.",
            );
        }

        return $next($request);
    }
}
