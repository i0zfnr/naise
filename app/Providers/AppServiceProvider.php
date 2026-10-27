<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production') && ! $this->app->runningInConsole()) {
            if (config('app.debug')) {
                throw new RuntimeException('APP_DEBUG must be false when APP_ENV is production.');
            }

            $requiredProductionValues = [
                'APP_KEY' => config('app.key'),
                'NAISE_ADMIN_EMAIL' => config('services.naise.admin_email'),
                'NAISE_ADMIN_PASSWORD_HASH' => config('services.naise.admin_password_hash'),
                'NAISE_STORE_PASSCODE_HASH' => config('services.naise.store_passcode_hash'),
            ];
            $missingProductionValues = array_keys(array_filter($requiredProductionValues, static fn (mixed $value): bool => blank($value)));

            if ($missingProductionValues !== []) {
                throw new RuntimeException('Production configuration is missing: '.implode(', ', $missingProductionValues).'.');
            }

            if (! filter_var(config('services.naise.admin_email'), FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('NAISE_ADMIN_EMAIL must be a valid email address in production.');
            }

            if (! config('session.secure') || ! config('session.encrypt')) {
                throw new RuntimeException('Production sessions require secure cookies and SESSION_ENCRYPT=true.');
            }

            $database = config('database.connections.'.config('database.default'));

            if (($database['driver'] ?? null) === 'mysql' && (($database['username'] ?? null) === 'root' || blank($database['password'] ?? null))) {
                throw new RuntimeException('Production MySQL requires a dedicated non-root user and a non-empty password.');
            }
        }

        RateLimiter::for('admin-login', static function (Request $request): array {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('admin-ip:'.$request->ip()),
                Limit::perMinute(5)->by('admin-email:'.hash('sha256', $email)),
            ];
        });

        RateLimiter::for('store-mode', static function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('order-create', static function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('receipt-upload', static function (Request $request): Limit {
            return Limit::perMinute(6)->by((string) ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('profile-update', static function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('voucher-redeem', static function (Request $request): Limit {
            return Limit::perMinute(3)->by((string) ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('payment-webhook', static function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->ip());
        });

        RateLimiter::for('staff-actions', static function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip().'|'.$request->session()->getId());
        });
    }
}
