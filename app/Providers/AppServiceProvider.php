<?php

namespace App\Providers;

use App\Database\Connections\PostgresConnection;
use App\Support\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Connection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
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
                ->greeting('Reset your password')
                ->line('We received a request to reset the password for your Scrutium workspace.')
                ->action('Choose a new password', $url)
                ->line('This link expires in 60 minutes and can be used only once.')
                ->line('If you did not request this, you can ignore this email.');
        });

        View::composer('*', function ($view) {
            $view->with('workspace', TenantContext::tenant());
        });

        $autoMigrate = filter_var(env('SCRUTIUM_AUTO_MIGRATE', true), FILTER_VALIDATE_BOOLEAN);
        if ($autoMigrate && env('DB_CONNECTION') === 'pgsql') {
            try {
                $lockPath = sys_get_temp_dir().'/scrutium_migrate.lock';
                $lock = @fopen($lockPath, 'c');
                if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
                    try {
                        if (! Schema::hasTable('tenants') || ! Schema::hasTable('password_reset_tokens')) {
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
