<?php

namespace App\Http\Middleware;

use App\Exceptions\ProblemException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Must run after `auth:sanctum` — it only checks the role of whoever that middleware already
 * resolved onto the request. An unauthenticated caller is rejected there with a 401, never
 * reaching here.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            throw ProblemException::forbidden();
        }

        return $next($request);
    }
}
