<?php

use Illuminate\Support\Facades\Process;

/**
 * There is deliberately no public readiness endpoint.
 *
 * This application refuses to expose diagnostic routes over the web (see
 * `AuthTest` → "does not expose diagnostic routes"), so a probe that reveals
 * which components are healthy, or how many jobs are queued, is a disclosure
 * risk and is not added here.
 *
 * The split is therefore:
 *   - `/up`                    liveness only. Says the process boots. No component detail.
 *   - `socialhub:doctor --json` readiness and capability report. Runs on the host,
 *                               over SSH or from a container exec, not over HTTP.
 */
it('serves a liveness probe that discloses nothing about the installation', function (): void {
    $response = $this->get('/up');

    $response->assertOk();

    $body = (string) $response->getContent();

    expect($body)->not->toContain(config('app.key'))
        ->and($body)->not->toContain('DB_')
        ->and($body)->not->toContain('redis')
        ->and($body)->not->toContain('postgres')
        ->and($body)->not->toContain('queue');
});

it('has no public readiness endpoint', function (): void {
    foreach (['/health', '/health/db', '/health/queue', '/status'] as $path) {
        $this->get($path)->assertNotFound();
    }
});

it('answers readiness through the doctor command instead', function (): void {
    $result = Process::run([
        PHP_BINARY,
        base_path('artisan'),
        'socialhub:doctor',
        '--json',
    ]);

    expect($result->exitCode())->toBe(0);

    // The report is machine-readable and, critically, carries no values: only
    // whether a credential is present.
    $decoded = json_decode($result->output(), true);

    expect($decoded)->toBeArray()
        ->and(json_encode($decoded))->not->toContain(config('app.key'));
});
