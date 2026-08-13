<?php

use App\Exceptions\ProblemException;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\LimitRequestSize;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // The major version lives in the path, per `docs/api-design.md` §10. Within a version
        // only backwards-compatible changes ship; anything breaking needs /v2.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Request bodies are tiny JSON documents in this API; reject oversized payloads before
        // PHP spends memory decoding them. Response headers are global so even error responses
        // (including ones Laravel creates before reaching a route) inherit the safe defaults.
        $middleware->append([
            // Order matters: this outer wrapper also adds headers to a 413 emitted by the
            // request-size guard below.
            SecurityHeaders::class,
            LimitRequestSize::class,
        ]);

        // Per-IP throttles are only meaningful when `Request::ip()` represents the browser,
        // rather than an upstream load balancer. Trust *only* the proxies configured by the
        // deployer — trusting every X-Forwarded-For header on a direct server would let an
        // attacker select a fresh IP and bypass all rate limits.
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('API_TRUSTED_PROXIES', '')),
        )));
        if ($trustedProxies !== []) {
            $middleware->trustProxies($trustedProxies);
        }

        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*'),
        );

        // Exceptions are finalized outside the normal route middleware pipeline. Apply the same
        // policy here so error responses cannot be framed, sniffed, or cached as credentials.
        $exceptions->respond(function ($response, $exception, Request $request) {
            return app(SecurityHeaders::class)->apply($request, $response);
        });

        // Every error leaves this API as an RFC 9457 problem document. Framework exceptions
        // are translated here so no individual handler has to remember to do it.

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            $errors = [];

            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = ['field' => $field, 'message' => $message];
                }
            }

            return ProblemException::validationFailed($errors)->render($request);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return $request->is('v1/*')
                ? ProblemException::unauthorized()->render($request)
                : null;
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            return $request->is('v1/*')
                ? ProblemException::forbidden()->render($request)
                : null;
        });

        // A missing route, an unresolvable route binding and someone else's resource all
        // arrive here, and all three are answered identically on purpose: a 403 would confirm
        // the resource exists, which is itself a disclosure.
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            return $request->is('v1/*')
                ? ProblemException::notFound()->render($request)
                : null;
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if (! $request->is('v1/*') || $e->getStatusCode() !== 429) {
                return null;
            }

            $headers = $e->getHeaders();

            return ProblemException::rateLimited((int) ($headers['Retry-After'] ?? 60))
                ->render($request)
                ->withHeaders($headers);
        });
    })->create();

// The custom Vercel PHP runtime can bypass Laravel's deferred provider loading.
// Register the view service explicitly so framework response and exception factories
// remain available even though this application exposes an API rather than web pages.
if (! $app->bound('view')) {
    $app->register(\Illuminate\View\ViewServiceProvider::class);
}

// Vercel's serverless filesystem is read-only everywhere except /tmp — Blade still needs
// somewhere to write compiled view templates regardless of which cache/session driver is
// configured, so the whole storage path is redirected there. VERCEL is set automatically by
// the platform; this is a no-op everywhere else (a plain VPS/Laravel Cloud deploy).
if (getenv('VERCEL')) {
    $storagePath = '/tmp/storage';

    foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'framework/testing', 'logs', 'app/public'] as $dir) {
        @mkdir("{$storagePath}/{$dir}", recursive: true);
    }

    $app->useStoragePath($storagePath);
}

return $app;
