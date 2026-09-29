<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Social\Contracts\ProviderCapabilities;
use App\Services\Social\SocialProviderRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reports the health of the social provider layer.
 *
 * Prints only presence flags (`set` / `missing`) — never a credential value, a
 * token, or a client secret.
 */
class SocialHubDoctorCommand extends Command
{
    protected $signature = 'socialhub:doctor {--json : Emit machine-readable output}';

    protected $description = 'Report social provider configuration and infrastructure health without printing any secret';

    public function handle(SocialProviderRegistry $registry): int
    {
        $descriptors = $registry->describe();
        $health = $this->health();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'providers' => array_map(
                    static fn ($descriptor): array => $descriptor->toArray(),
                    $descriptors,
                ),
                'infrastructure' => $health,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($descriptors, $health);
        }

        $this->renderProviderTable($descriptors);
        $this->renderCapabilityTable($descriptors);
        $this->renderHealthTable($health);

        if ($problems = $this->problems($descriptors, $health)) {
            $this->newLine();
            $this->components->warn('Action required:');
            foreach ($problems as $problem) {
                $this->components->twoColumnDetail('<fg=yellow>'.$problem, '');
            }
        }

        $this->newLine();
        $this->line('No credential values are printed by this command, by design.');

        return $this->exitCode($descriptors, $health);
    }

    /**
     * @param  list<\App\Services\Social\Support\ProviderDescriptor>  $descriptors
     */
    private function renderProviderTable(array $descriptors): void
    {
        $this->components->info('Providers');

        $rows = [];

        foreach ($descriptors as $descriptor) {
            $rows[] = [
                $descriptor->key,
                $descriptor->name,
                $descriptor->configured ? '<fg=green>configured</>' : '<fg=yellow>not configured</>',
                $descriptor->implemented ? '<fg=green>implemented</>' : '<fg=yellow>pending</>',
                $descriptor->requiresAppReview ? 'yes' : 'no',
                $this->credentialStatus($descriptor->key),
                $descriptor->notConfiguredReason ?? '-',
            ];
        }

        $this->table(
            ['Key', 'Provider', 'Status', 'Class', 'App review', 'Credentials', 'Reason'],
            $rows,
        );
    }

    /**
     * @param  list<\App\Services\Social\Support\ProviderDescriptor>  $descriptors
     */
    private function renderCapabilityTable(array $descriptors): void
    {
        $this->components->info('Capabilities (verified matrix, per SOCIAL-PROVIDERS.md)');

        $flags = ProviderCapabilities::flags();
        $rows = [];

        foreach ($descriptors as $descriptor) {
            $enabled = [];

            foreach ($flags as $flag) {
                if ($descriptor->capabilities->supports($flag)) {
                    $enabled[] = $flag;
                }
            }

            $rows[] = [
                $descriptor->key,
                (string) count($enabled).'/'.count($flags),
                implode(', ', $enabled),
                implode(', ', $descriptor->capabilities->metrics),
            ];
        }

        $this->table(['Key', 'Flags', 'Enabled', 'Metrics reported'], $rows);
    }

    /**
     * @param  array<string, array{ok: bool, detail: string}>  $health
     */
    private function renderHealthTable(array $health): void
    {
        $this->components->info('Infrastructure');

        $rows = [];

        foreach ($health as $check => $result) {
            $rows[] = [
                $check,
                $result['ok'] ? '<fg=green>ok</>' : '<fg=red>failing</>',
                $result['detail'],
            ];
        }

        $this->table(['Check', 'Status', 'Detail'], $rows);
    }

    /**
     * Report which credential keys are set, by name only.
     */
    private function credentialStatus(string $provider): string
    {
        $credentials = (array) config(sprintf('socialhub.providers.%s.credentials', $provider), []);

        if ($credentials === []) {
            return 'n/a';
        }

        $set = [];
        $missing = [];

        foreach ($credentials as $name => $value) {
            if (is_string($value) && trim($value) !== '') {
                $set[] = $name;
            } else {
                $missing[] = $name;
            }
        }

        $parts = [];

        if ($set !== []) {
            $parts[] = sprintf('set: %s', implode(', ', $set));
        }

        if ($missing !== []) {
            $parts[] = sprintf('missing: %s', implode(', ', $missing));
        }

        return implode(' | ', $parts);
    }

    /**
     * @return array<string, array{ok: bool, detail: string}>
     */
    private function health(): array
    {
        return [
            'database' => $this->check(fn (): string => DB::connection()->getPdo() ? 'connected' : 'no handle'),
            'redis' => $this->check(fn (): string => sprintf('ping %s', Redis::connection()->ping() === true ? 'ok' : 'unexpected')),
            'queue' => $this->check(fn (): string => (string) (config('socialhub.queue') ?: config('queue.default'))),
            'media_disk' => $this->check(function (): string {
                $disk = (string) (config('socialhub.media.disk') ?: 'local');

                Storage::disk($disk);

                return sprintf('%s (writable: %s)', $disk, $this->isWritable($disk) ? 'yes' : 'no');
            }),
            'oauth_state_store' => $this->check(function (): string {
                return class_exists('App\\Models\\OauthState')
                    ? 'OauthState model available'
                    : 'OauthState model missing — connections will fail closed';
            }),
        ];
    }

    private function isWritable(string $disk): bool
    {
        $path = Storage::disk($disk)->path('.socialhub-write-probe');

        if ($path === '') {
            return false;
        }

        $written = @file_put_contents($path, 'probe');
        @unlink($path);

        return $written !== false;
    }

    /**
     * @param  callable(): string  $probe
     * @return array{ok: bool, detail: string}
     */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => true, 'detail' => $probe()];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * @param  list<\App\Services\Social\Support\ProviderDescriptor>  $descriptors
     * @param  array<string, array{ok: bool, detail: string}>  $health
     * @return list<string>
     */
    private function problems(array $descriptors, array $health): array
    {
        $problems = [];

        foreach ($descriptors as $descriptor) {
            if (! $descriptor->implemented) {
                $problems[] = sprintf(
                    'Provider "%s" has no implementation yet (expected %s).',
                    $descriptor->key,
                    $descriptor->class ?? 'a class in config/socialhub.php',
                );
            } elseif (! $descriptor->configured) {
                $problems[] = sprintf('Provider "%s" is missing credentials: %s', $descriptor->key, $descriptor->notConfiguredReason);
            }
        }

        foreach ($health as $check => $result) {
            if (! $result['ok']) {
                $problems[] = sprintf('Infrastructure check "%s" is failing: %s', $check, $result['detail']);
            }
        }

        return $problems;
    }

    /**
     * @param  list<\App\Services\Social\Support\ProviderDescriptor>  $descriptors
     * @param  array<string, array{ok: bool, detail: string}>  $health
     */
    private function exitCode(array $descriptors, array $health): int
    {
        // A clean bill of health is not required for a successful report: the
        // command's job is to tell the operator what is wrong, not to gate CI.
        return self::SUCCESS;
    }
}
