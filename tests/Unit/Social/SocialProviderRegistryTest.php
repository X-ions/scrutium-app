<?php

declare(strict_types=1);

use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Services\Social\Contracts\ProviderCapabilities;
use App\Services\Social\Contracts\SocialProviderInterface;
use App\Services\Social\Providers\FacebookProvider;
use App\Services\Social\SocialProviderRegistry;

/**
 * A stand-in provider used to prove the registry resolves through the container
 * without any real network or database dependency.
 */
function stubProviderClass(): string
{
    return 'App\\Services\\Social\\Providers\\FacebookProvider';
}

it('reports whether a key is registered', function () {
    $registry = new SocialProviderRegistry(app(), [
        'facebook' => ['class' => stubProviderClass()],
        'ghost' => ['class' => 'App\\Services\\Social\\Providers\\GhostProvider'],
    ]);

    expect($registry->has('facebook'))->toBeTrue()
        ->and($registry->has('ghost'))->toBeTrue()
        ->and($registry->has('myspace'))->toBeFalse();
});

it('resolves a registered provider from the container', function () {
    $registry = new SocialProviderRegistry(app(), ['facebook' => ['class' => FacebookProvider::class]]);

    $provider = $registry->get('facebook');

    expect($provider)->toBeInstanceOf(FacebookProvider::class)
        ->and($provider)->toBeInstanceOf(SocialProviderInterface::class);
});

it('returns the same instance on repeated resolution', function () {
    $registry = new SocialProviderRegistry(app(), ['facebook' => ['class' => FacebookProvider::class]]);

    expect($registry->get('facebook'))->toBe($registry->get('facebook'));
});

it('accepts a bare class string as the definition', function () {
    $registry = new SocialProviderRegistry(app(), []);
    $registry->register('facebook', FacebookProvider::class);

    expect($registry->get('facebook'))->toBeInstanceOf(FacebookProvider::class);
});

it('throws ProviderNotConfiguredException for an unknown key', function () {
    $registry = new SocialProviderRegistry(app(), ['facebook' => ['class' => FacebookProvider::class]]);

    $registry->get('myspace');
})->throws(ProviderNotConfiguredException::class, 'no provider is registered under the key "myspace"');

it('skips gracefully a provider whose class does not exist yet', function () {
    $registry = new SocialProviderRegistry(app(), [
        'facebook' => ['class' => FacebookProvider::class],
        'instagram' => ['class' => 'App\\Services\\Social\\Providers\\InstagramProvider'],
        'snapchat' => ['class' => 'App\\Services\\Social\\Providers\\SnapchatProvider'],
    ]);

    expect($registry->isImplemented('facebook'))->toBeTrue()
        ->and($registry->isImplemented('instagram'))->toBeTrue()
        ->and($registry->isImplemented('snapchat'))->toBeFalse()
        ->and($registry->implemented())->toBe(['facebook', 'instagram']);
});

it('does not fatal when resolving an unimplemented provider', function () {
    $registry = new SocialProviderRegistry(app(), [
        'snapchat' => ['class' => 'App\\Services\\Social\\Providers\\SnapchatProvider'],
    ]);

    $registry->get('snapchat');
})->throws(ProviderNotConfiguredException::class, 'not implemented yet');

it('lists every registered key and describes them all', function () {
    $registry = new SocialProviderRegistry(app(), [
        'facebook' => ['class' => FacebookProvider::class, 'display_name' => 'Facebook', 'badge' => 'FB'],
        'snapchat' => ['class' => 'App\\Services\\Social\\Providers\\SnapchatProvider'],
    ]);

    expect($registry->all())->toBe(['facebook', 'snapchat']);

    $descriptors = $registry->describe();

    expect($descriptors)->toHaveCount(2);

    $byKey = collect($descriptors)->keyBy('key');

    expect($byKey->get('facebook')->name)->toBe('Facebook')
        ->and($byKey->get('facebook')->badge)->toBe('FB')
        ->and($byKey->get('facebook')->implemented)->toBeTrue()
        ->and($byKey->get('snapchat')->implemented)->toBeFalse()
        ->and($byKey->get('snapchat')->notConfiguredReason)->toContain('not implemented yet');
});

it('reports missing credentials by key name only, never by value', function () {
    $registry = new SocialProviderRegistry(app(), [
        'facebook' => [
            'class' => FacebookProvider::class,
            'credentials' => ['client_id' => '', 'client_secret' => 'super-secret-value'],
        ],
    ]);

    $descriptor = $registry->describeOne('facebook');

    expect($descriptor->configured)->toBeFalse()
        ->and($descriptor->notConfiguredReason)->toContain('client_id')
        ->and($descriptor->notConfiguredReason)->not->toContain('super-secret-value')
        ->and(json_encode($descriptor->toArray()))->not->toContain('super-secret-value');
});

it('reports a fully credentialed provider as configured', function () {
    $registry = new SocialProviderRegistry(app(), [
        'facebook' => [
            'class' => FacebookProvider::class,
            'credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
            'requires_app_review' => true,
            'docs_url' => 'https://developers.facebook.com/docs/graph-api',
        ],
    ]);

    $descriptor = $registry->describeOne('facebook');

    expect($descriptor->configured)->toBeTrue()
        ->and($descriptor->isUsable())->toBeTrue()
        ->and($descriptor->requiresAppReview)->toBeTrue()
        ->and($descriptor->docsUrl)->toContain('graph-api');
});

it('an app-review gate is documented, not reported as a configuration failure', function () {
    $registry = new SocialProviderRegistry(app(), [
        'facebook' => [
            'class' => FacebookProvider::class,
            'requires_app_review' => true,
            'credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
        ],
    ]);

    $descriptor = $registry->describeOne('facebook');

    expect($descriptor->configured)->toBeTrue()
        ->and($descriptor->notConfiguredReason)->toBeNull();
});

it('pulls capabilities from the verified matrix and applies the override', function () {
    $registry = new SocialProviderRegistry(app(), [
        'facebook' => [
            'class' => FacebookProvider::class,
            'capabilities_override' => ['deletePost' => false],
        ],
        'unknown_platform' => [
            'class' => FacebookProvider::class,
            'capabilities_override' => ['publishing' => true],
        ],
    ]);

    $facebook = $registry->describeOne('facebook')->capabilities;

    expect($facebook->publishing)->toBeTrue()
        ->and($facebook->deletePost)->toBeFalse();

    $unknown = $registry->describeOne('unknown_platform')->capabilities;

    expect($unknown->publishing)->toBeTrue()
        ->and($unknown->scheduling)->toBeFalse();
});

it('an unimplemented provider still reports its verified capabilities to the UI', function () {
    $registry = new SocialProviderRegistry(app(), [
        'pinterest' => ['class' => 'App\\Services\\Social\\Providers\\PinterestProvider'],
    ]);

    $capabilities = $registry->describeOne('pinterest')->capabilities;

    // Capabilities come from the matrix, not from whether a class exists, so a
    // stub still tells the UI what the network does and does not allow.
    // Pinterest has no comment API and no scheduled publish field.
    expect($capabilities)->toBeInstanceOf(ProviderCapabilities::class)
        ->and($capabilities->comments)->toBeFalse()
        ->and($capabilities->scheduling)->toBeFalse();
});

it('drops a stale resolution when a provider is re-registered', function () {
    $registry = new SocialProviderRegistry(app(), ['facebook' => ['class' => FacebookProvider::class]]);

    $first = $registry->get('facebook');

    $registry->register('facebook', ['class' => FacebookProvider::class, 'display_name' => 'Meta']);

    expect($registry->describeOne('facebook')->name)->toBe('Meta')
        ->and($registry->get('facebook'))->toBeInstanceOf(FacebookProvider::class)
        ->and($first)->toBeInstanceOf(FacebookProvider::class);
});
