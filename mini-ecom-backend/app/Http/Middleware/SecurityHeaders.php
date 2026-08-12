<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches the response headers a JSON API should never be without.
 *
 * Each one closes a specific hole rather than being added for a scanner's benefit:
 *
 * - `X-Content-Type-Options: nosniff` — stops a browser second-guessing `application/json`
 *   and executing a response body that happens to contain markup.
 * - `X-Frame-Options: DENY` + `frame-ancestors 'none'` — nothing here is meant to be framed,
 *   and the error pages that *are* HTML should not be clickjackable.
 * - `Referrer-Policy: no-referrer` — the password-reset and email-verification flows carry a
 *   token in the query string. Without this the token leaks to every third-party asset the
 *   landing page loads, and into their access logs.
 * - `Content-Security-Policy: default-src 'none'` — a JSON response has no legitimate need to
 *   load anything. If a payload is ever reflected into an HTML error page, this is what stops
 *   it executing.
 * - `Strict-Transport-Security` — sent only over HTTPS (a browser ignores it on plain HTTP,
 *   and asserting it locally would pin developers to a scheme they are not serving).
 *
 * Authentication responses additionally get `Cache-Control: no-store`. A login or refresh body
 * contains a bearer token; the framework's default `no-cache, private` still permits a shared
 * proxy or the browser's disk cache to keep a copy of it.
 */
class SecurityHeaders
{
    /**
     * Paths whose response bodies contain credentials.
     */
    private const CREDENTIAL_PATHS = [
        'v1/auth/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $this->apply($request, $next($request));
    }

    /**
     * The exception renderer runs outside the normal route middleware pipeline. Keeping this
     * method public lets `bootstrap/app.php` apply the exact same headers to a 413, 429, 404, or
     * any other framework-rendered failure — otherwise the most security-sensitive responses
     * would be the only ones missing the policy.
     */
    public function apply(Request $request, Response $response): Response
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()',
        ];

        // The public welcome page uses stylesheet and script assets. A zero-source CSP is the
        // strongest correct policy for JSON, but would intentionally make that page blank, so
        // bind it to the API rather than breaking a route this middleware does not secure.
        if ($request->is('v1/*')) {
            $headers['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
            $headers['Cross-Origin-Resource-Policy'] = 'same-origin';
        }

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        if ($request->is(...self::CREDENTIAL_PATHS)) {
            $headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, private';
            $headers['Pragma'] = 'no-cache';
        }

        foreach ($headers as $name => $value) {
            // A controller that has deliberately set its own value (a cached catalogue
            // response setting `Cache-Control`, for instance) keeps it.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // `Cache-Control` on a credential response is not negotiable, so it overrides.
        if ($request->is(...self::CREDENTIAL_PATHS)) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        }

        // The CORS allowlist answers a different Access-Control-Allow-Origin value for different
        // request origins. Tell CDNs and browser caches that Origin is part of the response
        // selection key, otherwise a cached allowlist decision could be served to another site.
        if ($request->is('v1/*') && $request->headers->has('Origin')) {
            $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary', ''))));
            if (! in_array('Origin', $vary, true)) {
                $vary[] = 'Origin';
                $response->headers->set('Vary', implode(', ', $vary));
            }
        }

        // The framework leaks its identity by default on some stacks.
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
