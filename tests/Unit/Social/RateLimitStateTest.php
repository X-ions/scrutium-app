<?php

declare(strict_types=1);

use App\Services\Social\Support\RateLimitState;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('parses Retry-After as delta seconds', function () {
    $state = RateLimitState::fromHeaders(['Retry-After' => '120'], now: 1_000_000);

    expect($state->retryAfter)->toBe(120)
        ->and($state->source)->toBe('retry-after');
});

it('parses Retry-After as an HTTP date relative to now', function () {
    $now = 1_700_000_000;
    $date = gmdate('D, d M Y H:i:s', $now + 45).' GMT';

    $state = RateLimitState::fromHeaders(['Retry-After' => $date], now: $now);

    expect($state->retryAfter)->toBeGreaterThanOrEqual(43)
        ->and($state->retryAfter)->toBeLessThanOrEqual(45)
        ->and($state->source)->toBe('retry-after');
});

it('is case-insensitive about header names', function () {
    $state = RateLimitState::fromHeaders(['retry-after' => '30'], now: 1_000_000);

    expect($state->retryAfter)->toBe(30);
});

it('parses X-RateLimit-Reset as an epoch timestamp', function () {
    $now = 1_700_000_000;

    $state = RateLimitState::fromHeaders(['X-RateLimit-Reset' => (string) ($now + 90)], now: $now);

    expect($state->retryAfter)->toBe(90)
        ->and($state->resetAt)->toBe($now + 90)
        ->and($state->source)->toBe('x-ratelimit-reset');
});

it('treats a small X-RateLimit-Reset as a delta, not an epoch', function () {
    $now = 1_700_000_000;

    $state = RateLimitState::fromHeaders(['X-RateLimit-Reset' => '75'], now: $now);

    expect($state->resetAt)->toBe($now + 75)
        ->and($state->retryAfter)->toBe(75);
});

it('reads the remaining quota', function () {
    $state = RateLimitState::fromHeaders([
        'X-RateLimit-Remaining' => '0',
        'Retry-After' => '15',
    ], now: 1_000_000);

    expect($state->remaining)->toBe(0)
        ->and($state->isExhausted())->toBeTrue();
});

it('reports available quota as not exhausted', function () {
    $state = RateLimitState::fromHeaders(['X-RateLimit-Remaining' => '499']);

    expect($state->isExhausted())->toBeFalse();
});

it('prefers Retry-After over X-RateLimit-Reset', function () {
    $now = 1_700_000_000;

    $state = RateLimitState::fromHeaders([
        'Retry-After' => '10',
        'X-RateLimit-Reset' => (string) ($now + 600),
    ], now: $now);

    expect($state->retryAfter)->toBe(10)
        ->and($state->source)->toBe('retry-after');
});

it('falls back to the configured default when no header is usable', function () {
    $state = RateLimitState::fromHeaders(['X-RateLimit-Limit' => '600'], fallback: 45);

    expect($state->retryAfter)->toBe(45)
        ->and($state->source)->toBeNull();
});

it('clamps a zero Retry-After to one second', function () {
    $state = RateLimitState::fromHeaders(['Retry-After' => '0'], fallback: 60, now: 1_000_000);

    expect($state->retryAfter)->toBe(1);
});

it('rejects a relative-looking Retry-After instead of resolving it against now', function () {
    // strtotime('-5') would resolve to a plausible-looking but wrong delay, so
    // an unparseable value must fall through to the configured default.
    $state = RateLimitState::fromHeaders(['Retry-After' => '-5'], fallback: 60, now: 1_000_000);

    expect($state->retryAfter)->toBe(60)
        ->and($state->source)->toBeNull();
});

it('never returns a negative delay for a date already in the past', function () {
    $now = 1_700_000_000;
    $date = gmdate('D, d M Y H:i:s', $now - 600).' GMT';

    $state = RateLimitState::fromHeaders(['Retry-After' => $date], fallback: 60, now: $now);

    expect($state->retryAfter)->toBe(1);
});

it('ignores unparseable header values and falls back', function () {
    $state = RateLimitState::fromHeaders(['Retry-After' => 'soon please'], fallback: 30);

    expect($state->retryAfter)->toBe(30)
        ->and($state->source)->toBeNull();
});

it('ignores a non-numeric remaining header', function () {
    $state = RateLimitState::fromHeaders(['X-RateLimit-Remaining' => 'many']);

    expect($state->remaining)->toBeNull()
        ->and($state->isExhausted())->toBeFalse();
});

it('reads headers straight off an HTTP response', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['code' => 4]], 429, ['Retry-After' => '77']),
    ]);

    $response = Http::get('https://graph.facebook.com/v23.0/me');

    $state = RateLimitState::fromResponse($response, now: 1_000_000);

    expect($state->retryAfter)->toBe(77)
        ->and($state->source)->toBe('retry-after');
});

it('accepts an array-shaped header value', function () {
    $state = RateLimitState::fromHeaders(['Retry-After' => ['88']], now: 1_000_000);

    expect($state->retryAfter)->toBe(88);
});

it('exposes its state for logging', function () {
    $state = RateLimitState::fromHeaders([
        'Retry-After' => '20',
        'X-RateLimit-Remaining' => '0',
    ], now: 1_000_000);

    expect($state->toArray())->toBe([
        'retry_after' => 20,
        'remaining' => 0,
        'reset_at' => null,
        'source' => 'retry-after',
    ]);
});
