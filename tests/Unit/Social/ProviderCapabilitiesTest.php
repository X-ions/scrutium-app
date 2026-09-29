<?php

declare(strict_types=1);

use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Services\Social\Capabilities\PlatformCapabilities;
use App\Services\Social\Contracts\ProviderCapabilities;

it('reports every declared capability flag', function () {
    $capabilities = PlatformCapabilities::facebook();

    expect($capabilities->publishing)->toBeTrue()
        ->and($capabilities->imagePublishing)->toBeTrue()
        ->and($capabilities->scheduling)->toBeFalse()
        ->and($capabilities->deletePost)->toBeTrue();
});

it('matches the verified matrix for a platform that cannot publish a carousel', function () {
    $x = PlatformCapabilities::x();

    // X v2 exposes the chunked media upload, so video is publishable; a Post
    // still cannot mix media types into a carousel.
    expect($x->videoPublishing)->toBeTrue()
        ->and($x->carouselPublishing)->toBeFalse()
        ->and($x->views)->toBeFalse()
        ->and($x->reach)->toBeFalse()
        ->and($x->textPublishing)->toBeTrue();
});

it('never reports reach on a platform that does not provide it', function () {
    // Instagram media insights do report reach and impressions; Pinterest and
    // LinkedIn report impressions but never reach.
    expect(PlatformCapabilities::instagram()->reach)->toBeTrue()
        ->and(PlatformCapabilities::instagram()->impressions)->toBeTrue()
        ->and(PlatformCapabilities::youtube()->impressions)->toBeFalse()
        ->and(PlatformCapabilities::linkedin()->reach)->toBeFalse()
        ->and(PlatformCapabilities::pinterest()->reach)->toBeFalse()
        ->and(PlatformCapabilities::pinterest()->impressions)->toBeTrue()
        ->and(PlatformCapabilities::tiktok()->reach)->toBeFalse()
        ->and(PlatformCapabilities::tiktok()->impressions)->toBeFalse();
});

it('resolves a platform by key through named()', function () {
    $capabilities = ProviderCapabilities::named('tiktok');

    expect($capabilities->shorts)->toBeTrue()
        ->and($capabilities->deletePost)->toBeFalse()
        ->and($capabilities->linkPosts)->toBeFalse();
});

it('rejects an unknown platform key', function () {
    ProviderCapabilities::named('myspace');
})->throws(InvalidArgumentException::class);

it('supports() reads flags and reports unknown names as unsupported', function () {
    $capabilities = PlatformCapabilities::facebook();

    expect($capabilities->supports('publishing'))->toBeTrue()
        ->and($capabilities->supports('scheduling'))->toBeFalse()
        ->and($capabilities->supports('thisFlagDoesNotExist'))->toBeFalse();
});

it('assertSupports() is silent when the capability is present', function () {
    PlatformCapabilities::facebook()->assertSupports('publishing', 'facebook', 'Facebook');
})->throwsNoExceptions();

it('assertSupports() throws UnsupportedCapabilityException when absent', function () {
    // X cannot mix media into a carousel post, whatever the upload supports.
    PlatformCapabilities::x()->assertSupports('carouselPublishing', 'x', 'X');
})->throws(UnsupportedCapabilityException::class);

it('carries a user-facing message and remediation on the unsupported exception', function () {
    $capabilities = PlatformCapabilities::x();

    try {
        $capabilities->assertSupports('carouselPublishing', 'x', 'X');
        $this->fail('Expected an UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->capability)->toBe('carouselPublishing')
            ->and($e->provider)->toBe('x')
            ->and($e->userFacingError)->not->toBeNull()
            ->and($e->userFacingError->code)->toBe('unsupported_capability')
            ->and($e->userFacingError->userMessage)->toContain('X')
            ->and($e->userFacingError->retryable)->toBeFalse();
    }
});

it('assertSupportsMetric() throws for a metric the platform does not report', function () {
    // Instagram insights carry reach and impressions but no follower total on
    // the media resource, so a follower metric is genuinely not reported.
    PlatformCapabilities::youtube()->assertSupportsMetric('impressions', 'youtube', 'YouTube');
})->throws(UnsupportedCapabilityException::class);

it('assertSupportsMetric() passes for a reported metric', function () {
    PlatformCapabilities::instagram()->assertSupportsMetric('views', 'instagram', 'Instagram');
})->throwsNoExceptions();

it('serialises to an array containing every flag and list', function () {
    $array = PlatformCapabilities::pinterest()->toArray();

    foreach (ProviderCapabilities::flags() as $flag) {
        expect($array)->toHaveKey($flag);
    }

    expect($array)->toHaveKeys(['metrics', 'constraints', 'requiredScopes'])
        ->and($array['requiredScopes'])->toBe(['pins:read', 'pins:write', 'boards:read', 'user_accounts:read']);
});

it('json-serialises identically to toArray()', function () {
    $capabilities = PlatformCapabilities::youtube();

    expect(json_decode(json_encode($capabilities), true))->toBe($capabilities->toArray());
});

it('is readonly — mutating a flag is impossible', function () {
    $capabilities = PlatformCapabilities::facebook();

    $this->expectException(Error::class);
    $capabilities->publishing = false;
});

it('narrows to the intersection of two capability sets', function () {
    $platform = PlatformCapabilities::facebook();
    $account = $platform->withOverrides(['deletePost' => false, 'webhooks' => false]);

    $narrowed = $platform->narrow($account);

    expect($narrowed->publishing)->toBeTrue()
        ->and($narrowed->deletePost)->toBeFalse()
        ->and($narrowed->webhooks)->toBeFalse();
});

it('narrowing drops metrics that either side lacks', function () {
    $narrowed = PlatformCapabilities::facebook()->narrow(new ProviderCapabilities(
        metrics: ['views', 'impressions'],
    ));

    expect($narrowed->metrics)->toBe(['views', 'impressions']);
});

it('exposes constraints keyed by capability as remediation hints', function () {
    $capabilities = PlatformCapabilities::facebook();

    expect($capabilities->constraints)->toContain('textPublishing:Facebook text-only posts are deprecated for most page types; publish with media or use a link post instead.')
        ->and($capabilities->remediationHintFor('textPublishing'))
        ->toContain('deprecated');
});

it('lists the same flag set on every platform instance', function () {
    foreach (PlatformCapabilities::platforms() as $key => $label) {
        $array = PlatformCapabilities::for($key)->toArray();

        expect($array)->toHaveKeys(ProviderCapabilities::flags());
    }
});
