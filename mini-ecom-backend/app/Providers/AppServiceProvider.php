<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\Messages\MailMessage;
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
        $this->configureAuthNotificationUrls();
    }

    /**
     * This is a pure JSON API with no Blade views to click through to, so the stock password
     * reset notification's default URL (a named web route that doesn't exist here) is
     * replaced with a link into whatever client actually renders a page — a SPA or mobile app
     * reading `API_FRONTEND_URL`. The client extracts the token back out of the query string
     * and posts it to `/v1/auth/password/reset`.
     *
     * Email verification has no equivalent override: it is a typed-in 6-digit code, not a
     * link, sent by `App\Notifications\EmailVerificationCodeNotification` — see
     * `User::sendEmailVerificationNotification()`.
     */
    private function configureAuthNotificationUrls(): void
    {
        $buildUrl = function (User $user, string $token): string {
            $query = http_build_query(['token' => $token, 'email' => $user->email]);

            return rtrim((string) config('api.frontend_url'), '/')."/reset-password?{$query}";
        };

        ResetPassword::createUrlUsing($buildUrl);

        // Overrides the stock notification's plain-text mail body with the branded template
        // shared by `EmailVerificationCodeNotification`.
        ResetPassword::toMailUsing(function (User $user, string $token) use ($buildUrl): MailMessage {
            return (new MailMessage)
                ->subject('Reset your '.config('app.name').' password')
                ->view('emails.reset-password', [
                    'url' => $buildUrl($user, $token),
                    'count' => (int) config('auth.passwords.users.expire'),
                ]);
        });
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
     * Rate limits are keyed to the identity that actually needs protecting, not just the source
     * address. An IP-only login throttle stops one laptop guessing thousands of accounts, but a
     * password-spraying botnet simply uses a fresh address for each attempt against the same
     * customer. Login therefore applies both an IP key and a normalized-email key.
     *
     * Laravel's ThrottleRequests middleware emits X-RateLimit-Limit, X-RateLimit-Remaining
     * and X-RateLimit-Reset for each, and Retry-After on a 429.
     */
    private function configureRateLimiters(): void
    {
        $limits = config('api.rate_limits');

        // Registration, password-reset, and verification-mail resend are limited per IP. The
        // registration 409 response is an unavoidable account-existence disclosure, so this
        // keeps it impractical to enumerate addresses in bulk.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute($limits['auth'])
            ->by('auth-ip:'.$request->ip()));

        RateLimiter::for('login', function (Request $request) use ($limits): array {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute($limits['auth'])->by('login-ip:'.$request->ip()),
                Limit::perMinute($limits['login_email'])->by('login-email:'.hash('sha256', $email)),
            ];
        });

        // A refresh token endpoint cannot use auth:sanctum (it is invoked precisely after the
        // access token expires), but it must still not accept an unlimited stream of guesses.
        RateLimiter::for('refresh', fn (Request $request) => Limit::perMinute($limits['refresh'])
            ->by('refresh-ip:'.$request->ip()));

        RateLimiter::for('verification', fn (Request $request) => [
            Limit::perMinute($limits['verification'])->by('verification-ip:'.$request->ip()),
            Limit::perMinute($limits['verification'])->by('verification-user:'.($request->user()?->id ?: $request->ip())),
        ]);

        // Telegram webhooks are unauthenticated at the framework level. The header signature is
        // still the identity proof; the throttle absorbs unauthenticated floods before the bot
        // client or database is touched.
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute($limits['webhook'])
            ->by('webhook-ip:'.$request->ip()));

        RateLimiter::for('authenticated', fn (Request $request) => Limit::perMinute($limits['authenticated'])
            ->by('user:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute($limits['catalog'])
            ->by('catalog-ip:'.$request->ip()));

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute($limits['checkout'])
            ->by('checkout-user:'.($request->user()?->id ?: $request->ip())));

        // Every verification reaches Bakong over HTTPS. Scope the limiter to the customer and
        // route rather than only the IP so a botnet cannot turn this into paid/API-token abuse.
        RateLimiter::for('payment', fn (Request $request) => Limit::perMinute($limits['payment'])
            ->by('payment-user:'.($request->user()?->id ?: $request->ip()).':'.$request->path()));
    }
}
