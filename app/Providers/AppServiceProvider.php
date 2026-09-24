<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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

        $autoMigrate = filter_var(env('SCRUTIUM_AUTO_MIGRATE', true), FILTER_VALIDATE_BOOLEAN);
        if ($autoMigrate && env('DB_CONNECTION') === 'pgsql') {
            try {
                if (! Schema::hasTable('tenants')) {
                    Artisan::call('migrate', ['--force' => true]);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
