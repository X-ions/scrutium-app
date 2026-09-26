<?php

namespace App\Providers;

use App\Database\Connections\PostgresConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
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

        $autoMigrate = filter_var(env('SCRUTIUM_AUTO_MIGRATE', true), FILTER_VALIDATE_BOOLEAN);
        if ($autoMigrate && env('DB_CONNECTION') === 'pgsql') {
            try {
                $lockPath = sys_get_temp_dir().'/scrutium_migrate.lock';
                $lock = @fopen($lockPath, 'c');
                if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
                    try {
                        $migrator = app('migrator');
                        $repository = $migrator->getRepository();
                        $ranMigrations = $repository->repositoryExists() ? $repository->getRan() : [];
                        $pendingMigrations = array_diff(
                            array_keys($migrator->getMigrationFiles(database_path('migrations'))),
                            $ranMigrations,
                        );

                        if (! Schema::hasTable('tenants') || $pendingMigrations !== []) {
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
