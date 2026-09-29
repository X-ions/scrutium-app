<?php

declare(strict_types=1);

use App\Services\Publishing\PayloadRedactor;

it('redacts a token even when the key is spelled differently', function () {
    $redacted = PayloadRedactor::redact([
        'page_access_token' => 'page-access-token-value',
        'graph_access_token' => 'another-secret',
        'client_secret' => 'app-secret',
        'Authorization' => 'Bearer abc123',
        'meta' => ['refresh_token' => 'refresh-value', 'nested' => ['app_secret' => 'deep']],
    ]);

    $serialised = (string) json_encode($redacted);

    expect($serialised)->not->toContain('page-access-token-value')
        ->and($serialised)->not->toContain('another-secret')
        ->and($serialised)->not->toContain('app-secret')
        ->and($serialised)->not->toContain('refresh-value')
        ->and($serialised)->not->toContain('deep')
        ->and($redacted['page_access_token'])->toBe('[redacted]')
        ->and($redacted['meta']['refresh_token'])->toBe('[redacted]')
        ->and($redacted['meta']['nested']['app_secret'])->toBe('[redacted]');
});

it('redacts a bearer credential that arrives under an innocent key', function () {
    $redacted = PayloadRedactor::redact([
        'message' => 'Bearer EAAabc123def456ghi789jkl012',
    ]);

    expect($redacted['message'])->toBe('[redacted]');
});

it('keeps ordinary provider fields readable for support', function () {
    $redacted = PayloadRedactor::redact([
        'id' => '9001',
        'permalink_url' => 'https://facebook.com/page/posts/9001',
        'created_time' => '2026-09-29T10:00:00+0000',
        'comments' => 12,
    ]);

    expect($redacted)->toBe([
        'id' => '9001',
        'permalink_url' => 'https://facebook.com/page/posts/9001',
        'created_time' => '2026-09-29T10:00:00+0000',
        'comments' => 12,
    ]);
});

it('truncates an oversized value so a caption echo cannot become a data dump', function () {
    $redacted = PayloadRedactor::redact(['message' => str_repeat('a', 2000)]);

    expect(strlen($redacted['message']))->toBeLessThan(600)
        ->and($redacted['message'])->toEndWith('[truncated]');
});

it('stops recursing once the payload is absurdly deep', function () {
    $payload = 'deep';
    $expected = 'deep';

    for ($i = 0; $i < 12; $i++) {
        $payload = ['level' => $payload];
        $expected = 'deep';
    }

    $redacted = PayloadRedactor::redact($payload);

    expect((string) json_encode($redacted))->toContain('[truncated]');
});

it('flattens an object that was handed in directly', function () {
    $value = (object) ['id' => '5', 'access_token' => 'secret-value-here'];

    expect(PayloadRedactor::redactValue($value))->toBe([
        'id' => '5',
        'access_token' => '[redacted]',
    ]);
});
