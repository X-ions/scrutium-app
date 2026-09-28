<?php

namespace App\Providers;

use App\Database\Connections\PostgresConnection;
use App\Support\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor(
            'pgsql',
            fn ($connection, $database, $prefix, array $config) => new PostgresConnection(
                $connection, $database, $prefix, $config
            )
        );

        $storage = getenv('APP_STORAGE_PATH') ?: ($_ENV['APP_STORAGE_PATH'] ?? null);
        if ($storage) {
            $this->app->useStoragePath($storage);
        }

        if ($this->app->bound('config') && $this->app->environment('production')) {
            $this->app['config']->set('app.maintenance.driver', 'file');
            $this->app['config']->set('app.maintenance.store', 'array');
        }
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));
        });

        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            return (new MailMessage)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->subject('Reset your Scrutium password')
                ->view('emails.password-reset', ['url' => $url]);
        });

        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            return (new MailMessage)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->subject('Verify your email for Scrutium')
                ->view('emails.verify-email', [
                    'first_name' => Str::before(trim((string) $notifiable->name), ' '),
                    'url' => $url,
                ]);
        });

        View::composer('*', function ($view) {
            $view->with('workspace', TenantContext::tenant());
        });

        RateLimiter::for('auth', function (Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(6)
                ->by($request->ip())
                ->response(function () {
                    return response()->json(['message' => 'Too many requests. Please try again later.'], 429);
                });
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(6)
                ->by($request->ip())
                ->response(function () {
                    return response()->json(['message' => 'Too many requests. Please try again later.'], 429);
                });
        });

        $autoMigrate = filter_var(env('SCRUTIUM_AUTO_MIGRATE', true), FILTER_VALIDATE_BOOLEAN);
        if ($autoMigrate && env('DB_CONNECTION') === 'pgsql') {
            try {
                $lockPath = sys_get_temp_dir().'/scrutium_migrate.lock';
                $lock = @fopen($lockPath, 'c');
                if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
                    try {
                        // Every table the app cannot boot without. Gating on
                        // the first migration alone would leave a deployed
                        // database pinned to whatever schema it already had,
                        // silently skipping later additions.
                        $required = [
                            'tenants',
                            'password_reset_tokens',
                            'security_events',
                            'devices',
                            'trusted_devices',
                            'user_sessions',
                            'security_notification_preferences',
                        ];

                        $missing = array_values(array_filter(
                            $required,
                            fn (string $table): bool => ! Schema::hasTable($table)
                        ));

                        if (
                            $missing !== []
                            || ! Schema::hasColumn('tenants', 'compact_layout')
                        ) {
                            Artisan::call('migrate', ['--force' => true]);
                        }
                    } finally {
                        flock($lock, LOCK_UN);
                        fclose($lock);
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
