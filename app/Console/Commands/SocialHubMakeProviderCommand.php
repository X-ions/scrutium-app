<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Social\Contracts\SocialProviderInterface;
use App\Services\Social\SocialProviderRegistry;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * Scaffolds a new provider class: the "add a provider in 5 minutes" path.
 *
 * Generates the class and the matching `config/socialhub.php` entry, both
 * templated with the interface's method signatures and a capabilities block.
 */
class SocialHubMakeProviderCommand extends GeneratorCommand
{
    protected $name = 'socialhub:make-provider';

    protected $description = 'Scaffold a social provider class and its config/socialhub.php entry';

    protected $type = 'Provider';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/socialhub/provider.stub';
    }

    protected function buildClass($name): string
    {
        return str_replace(
            ['{{ class }}', '{{ key }}', '{{ providerName }}'],
            [$this->providerClass(), $this->providerKey(), $this->providerName()],
            parent::buildClass($name),
        );
    }

    /**
     * The generated file and class are named after the provider class, not the
     * raw key, so `socialhub:make-provider tiktok` yields `TikTokProvider.php`.
     */
    protected function getNameInput(): string
    {
        return $this->providerClass();
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the provider class if it already exists'],
            ['config-only', null, InputOption::VALUE_NONE, 'Only print the config/socialhub.php entry'],
        ];
    }

    public function handle(): int
    {
        $key = $this->providerKey();
        $registry = $this->laravel->make(SocialProviderRegistry::class);

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            $this->components->error('The provider key must be lowercase alphanumeric with underscores.');

            return self::FAILURE;
        }

        if ($registry->has($key)) {
            $this->components->error(sprintf('Provider "%s" is already registered in config/socialhub.php.', $key));

            return self::FAILURE;
        }

        if ($this->option('config-only')) {
            $this->line($this->configBlock($key, $this->providerClass(), $this->providerNamespace()));

            return self::SUCCESS;
        }

        $result = (int) parent::handle();

        if ($result !== self::SUCCESS) {
            return $result;
        }

        $this->newLine();
        $this->components->info('Add this entry to the `providers` array in config/socialhub.php:');
        $this->line($this->configBlock($key, $this->providerClass(), $this->providerNamespace()));
        $this->newLine();
        $this->components->twoColumnDetail('Next', 'fill in the credential env keys, then run <info>php artisan socialhub:doctor</info>');
        $this->components->twoColumnDetail('Then', sprintf('implement every method on <info>%s</info> — do not stub a response', SocialProviderInterface::class));

        return self::SUCCESS;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Services\Social\Providers';
    }

    /**
     * @return array<string, string>
     */
    protected function replaceClass($name, $stub): string
    {
        return str_replace(
            ['{{ class }}', '{{ key }}', '{{ providerName }}'],
            [$name, $this->providerKey(), $this->providerName()],
            parent::replaceClass($name, $stub),
        );
    }

    private function providerKey(): string
    {
        return (string) $this->argument('name');
    }

    private function providerName(): string
    {
        return Str::headline($this->providerKey());
    }

    private function providerClass(): string
    {
        return Str::studly($this->providerKey()).'Provider';
    }

    private function providerNamespace(): string
    {
        return $this->laravel->getNamespace().'Services\Social\Providers';
    }

    private function configBlock(string $key, string $class, string $namespace): string
    {
        $envPrefix = Str::upper(Str::snake($key, '_')).'_';
        $studly = Str::studly($key);

        return <<<PHP
        '$key' => [
            'class' => {$namespace}\\{$class}::class,
            'display_name' => '{$studly}',
            'badge' => '{$this->badgeFor($key)}',
            'docs_url' => env('{$envPrefix}DOCS_URL'),
            'requires_app_review' => false,
            'env_prefix' => '{$envPrefix}',
            'capabilities_override' => [],
            'credentials' => [
                'client_id' => env('{$envPrefix}CLIENT_ID'),
                'client_secret' => env('{$envPrefix}CLIENT_SECRET'),
            ],
            'oauth' => [
                'client_id' => env('{$envPrefix}CLIENT_ID'),
                'client_secret' => env('{$envPrefix}CLIENT_SECRET'),
                'authorize_url' => env('{$envPrefix}AUTHORIZE_URL'),
                'token_url' => env('{$envPrefix}TOKEN_URL'),
                'pkce' => true,
                'scopes' => [],
                'default_scopes' => [],
            ],
        ],
        PHP;
    }

    private function badgeFor(string $key): string
    {
        return strtoupper(substr(str_replace('_', '', $key), 0, 2));
    }
}
