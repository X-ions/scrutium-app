<?php

declare(strict_types=1);

use App\Exceptions\Social\ProviderApiException;
use App\Exceptions\Social\RateLimitException;
use App\Services\Social\Data\UserFacingError;

it('carries the five fields the variant status card renders', function () {
    $error = new UserFacingError(
        code: 'permission_denied',
        userMessage: 'The connected account does not have permission to publish.',
        technicalMessage: 'Provider "instagram" returned HTTP 403.',
        retryable: false,
        remediation: 'Reconnect the account and approve all requested permissions.',
    );

    expect($error->code)->toBe('permission_denied')
        ->and($error->userMessage)->toBeString()->not->toBeEmpty()
        ->and($error->technicalMessage)->toBeString()->not->toBeEmpty()
        ->and($error->retryable)->toBeFalse()
        ->and($error->remediation)->toBeString()->not->toBeEmpty();
});

it('defaults to non-retryable with no remediation', function () {
    $error = UserFacingError::make('some_code', 'User facing.', 'Technical.');

    expect($error->retryable)->toBeFalse()
        ->and($error->remediation)->toBeNull()
        ->and($error->context)->toBe([]);
});

it('writes a user message that is actionable rather than technical', function () {
    $error = UserFacingError::make(
        'token_revoked',
        'Access to this account was withdrawn on the network. Reconnect it to continue.',
        'Provider "x" reported the token as invalid or revoked.',
    );

    expect($error->userMessage)->toContain('Reconnect')
        ->and($error->userMessage)->not->toContain('HTTP')
        ->and($error->technicalMessage)->toContain('revoked');
});

it('serialises every field in snake_case', function () {
    $error = UserFacingError::make('rate_limited', 'Slow down.', 'HTTP 429.', true, 'Wait.', ['provider' => 'x']);

    expect($error->toArray())->toBe([
        'code' => 'rate_limited',
        'user_message' => 'Slow down.',
        'technical_message' => 'HTTP 429.',
        'retryable' => true,
        'remediation' => 'Wait.',
        'context' => ['provider' => 'x'],
    ]);
});

it('json-serialises identically to toArray()', function () {
    $error = UserFacingError::make('code', 'user', 'tech');

    expect(json_decode(json_encode($error), true))->toBe($error->toArray());
});

it('is readonly — its fields cannot be reassigned', function () {
    $error = UserFacingError::make('code', 'user', 'tech');

    $this->expectException(Error::class);
    $error->code = 'other';
});

it('does not put credential material into its own serialised form', function () {
    $error = UserFacingError::make(
        'token_error',
        'Something went wrong.',
        'Provider "facebook" returned HTTP 401.',
        false,
        'Reconnect the account.',
        ['access_token' => 'EAAB-super-secret', 'authorization' => 'Bearer leak'],
    );

    $serialised = json_encode($error);

    expect($serialised)->not->toContain('EAAB-super-secret')
        ->and($serialised)->not->toContain('Bearer leak');
});

it('is attached to a ProviderApiException and surfaces through userMessage()', function () {
    $error = UserFacingError::make('provider_error', 'Facebook rejected the request.', 'HTTP 400.');

    $exception = new ProviderApiException('facebook', 400, '100', $error);

    expect($exception->userMessage())->toBe('Facebook rejected the request.')
        ->and($exception->userFacingError)->toBe($error);
});

it('falls back to the exception message when no user-facing error is attached', function () {
    $exception = new ProviderApiException('facebook', 500, null);

    expect($exception->userFacingError)->toBeNull()
        ->and($exception->userMessage())->toContain('facebook')
        ->and($exception->status)->toBe(500);
});

it('a rate-limited error is marked retryable and names its delay', function () {
    $exception = new RateLimitException('facebook', 90);

    expect($exception->retryAfter)->toBe(90)
        ->and($exception->userFacingError->retryable)->toBeTrue()
        ->and($exception->userFacingError->remediation)->toContain('No action needed')
        ->and($exception->context)->toMatchArray(['provider' => 'facebook', 'retry_after' => 90]);
});

it('keeps its message free of any token material', function () {
    $exception = new RateLimitException('facebook', 30);

    expect($exception->getMessage())->toBe('Provider "facebook" is rate limited; retry in 30 second(s).');
});
