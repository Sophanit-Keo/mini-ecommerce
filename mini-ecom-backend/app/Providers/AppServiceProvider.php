<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // The spec returns single resources bare and wraps only lists, which it does
        // explicitly as `{ data: [...] }` alongside a sibling `page` object. Laravel's
        // automatic `data` envelope would wrap both, so it is turned off and the envelope is
        // built where it is actually part of the contract.
        JsonResource::withoutWrapping();

        // A lazy load inside a resource is an N+1 that only shows up under real traffic.
        // Outside production it is an exception instead, so the test suite catches it.
        Model::preventLazyLoading(! $this->app->isProduction());

        $this->configureRateLimiters();
        $this->configurePasswordPolicy();
    }

    /**
     * Minimum 12 characters, checked against a breached-password list.
     *
     * The breach check is an outbound HTTPS call to the Have I Been Pwned range API, so it
     * runs in production only — otherwise every test and local registration would depend on
     * a third party being reachable.
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->uncompromised()
            : Password::min(12));
    }

    /**
     * The three scopes from `docs/api-design.md` §11.
     *
     * Laravel's ThrottleRequests middleware emits X-RateLimit-Limit, X-RateLimit-Remaining
     * and X-RateLimit-Reset for each, and Retry-After on a 429.
     */
    private function configureRateLimiters(): void
    {
        $limits = config('api.rate_limits');

        // Login and registration are throttled per IP. Registration is limited primarily as
        // an account-enumeration control: it returns 409 for an already-registered email,
        // which is a disclosure the throttle keeps impractical to exploit in bulk.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute($limits['auth'])->by($request->ip()));

        RateLimiter::for('authenticated', fn (Request $request) => Limit::perMinute($limits['authenticated'])
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute($limits['catalog'])->by($request->ip()));
    }
}
