<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Services\Social\Capabilities\PlatformCapabilities;
use App\Services\Social\Contracts\ProviderCapabilities;
use App\Services\Social\Contracts\SocialProviderInterface;
use App\Services\Social\Support\ProviderDescriptor;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

/**
 * Resolves provider keys to implementations from `config/socialhub.php`.
 *
 * No core code may branch on a platform name: everything goes through `get()`.
 * A configured provider whose class is not implemented yet is skipped and
 * reported through `describe()` rather than causing a fatal error.
 */
class SocialProviderRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $definitions = [];

    /**
     * @var array<string, SocialProviderInterface>
     */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
        array $definitions = [],
    ) {
        foreach ($definitions as $key => $definition) {
            $this->register((string) $key, $definition);
        }
    }

    /**
     * @param  class-string<SocialProviderInterface>|array<string, mixed>  $class
     */
    public function register(string $key, string|array $class, array $extra = []): void
    {
        $definition = is_array($class) ? $class : ['class' => $class];

        $this->definitions[$key] = array_merge($definition, $extra);
        $this->resolved = [];
    }

    public function has(string $provider): bool
    {
        return isset($this->definitions[$provider]);
    }

    public function isImplemented(string $provider): bool
    {
        $class = $this->classFor($provider);

        return $class !== null && class_exists($class);
    }

    /**
     * @throws ProviderNotConfiguredException when the key is unknown or not implemented
     */
    public function get(string $provider): SocialProviderInterface
    {
        if (! $this->has($provider)) {
            throw new ProviderNotConfiguredException(
                $provider,
                sprintf('no provider is registered under the key "%s"', $provider),
            );
        }

        if (isset($this->resolved[$provider])) {
            return $this->resolved[$provider];
        }

        $class = $this->classFor($provider);

        if ($class === null || ! class_exists($class)) {
            throw new ProviderNotConfiguredException(
                $provider,
                sprintf('the provider class %s is not implemented yet', $class ?? 'n/a'),
            );
        }

        $instance = $this->instantiate($class);

        if (! $instance instanceof SocialProviderInterface) {
            throw new ProviderNotConfiguredException(
                $provider,
                sprintf('%s does not implement %s', $class, SocialProviderInterface::class),
            );
        }

        return $this->resolved[$provider] = $instance;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * @return list<string>
     */
    public function implemented(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (string $key): bool => $this->isImplemented($key),
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function definition(string $provider): array
    {
        return $this->definitions[$provider] ?? [];
    }

    /**
     * @return list<ProviderDescriptor>
     */
    public function describe(): array
    {
        $descriptors = [];

        foreach ($this->definitions as $key => $definition) {
            $descriptors[] = $this->descriptorFor((string) $key, $definition);
        }

        return $descriptors;
    }

    public function describeOne(string $provider): ProviderDescriptor
    {
        return $this->descriptorFor($provider, $this->definition($provider));
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function descriptorFor(string $key, array $definition): ProviderDescriptor
    {
        $class = $this->classFor($key);
        $implemented = $class !== null && class_exists($class);
        $missing = $this->missingCredentialKeys($definition);
        $configured = $missing === [];

        $reason = null;

        if (! $implemented) {
            $reason = sprintf(
                'provider class %s is not implemented yet',
                $class ?? sprintf('for "%s"', $key),
            );
        } elseif (! $configured) {
            $reason = sprintf('missing environment values: %s', implode(', ', $missing));
        } elseif (($definition['requires_app_review'] ?? false) === true) {
            // Documented, not a blocker: the app review gate is external.
            $reason = null;
        }

        $capabilities = $this->capabilitiesFor($key, $definition);

        return new ProviderDescriptor(
            key: $key,
            name: (string) ($definition['display_name'] ?? PlatformCapabilities::displayName($key)),
            badge: (string) ($definition['badge'] ?? strtoupper(substr($key, 0, 2))),
            capabilities: $capabilities,
            configured: $configured,
            notConfiguredReason: $reason,
            requiresAppReview: (bool) ($definition['requires_app_review'] ?? false),
            docsUrl: $definition['docs_url'] ?? null,
            implemented: $implemented,
            class: $class,
            oauth: (array) ($definition['oauth'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function capabilitiesFor(string $key, array $definition): ProviderCapabilities
    {
        $overrides = (array) ($definition['capabilities_override'] ?? []);

        if (PlatformCapabilities::supports($key)) {
            return PlatformCapabilities::for($key)->withOverrides($overrides);
        }

        return (new ProviderCapabilities)->withOverrides($overrides);
    }

    /**
     * Credential keys that are declared but currently empty in the environment.
     * Only key *names* are ever reported — never values.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private function missingCredentialKeys(array $definition): array
    {
        $credentials = (array) ($definition['credentials'] ?? []);

        if ($credentials === []) {
            return [];
        }

        $missing = [];

        foreach ($credentials as $name => $value) {
            if (! is_string($value) || trim($value) === '') {
                $missing[] = (string) $name;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function classFor(string $provider): ?string
    {
        $class = $this->definitions[$provider]['class'] ?? null;

        return is_string($class) && $class !== '' ? $class : null;
    }

    /**
     * @param  class-string<SocialProviderInterface>  $class
     */
    private function instantiate(string $class): SocialProviderInterface
    {
        try {
            return $this->container->make($class);
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('Unable to instantiate social provider %s: %s', $class, $e->getMessage()),
                previous: $e,
            );
        }
    }
}
