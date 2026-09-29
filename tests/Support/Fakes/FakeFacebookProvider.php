<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

/**
 * The stand-in the registry resolves for the `facebook` provider key.
 *
 * The container resolves a class-string, so each network needs its own class to
 * bind independently; the behaviour lives in the shared base.
 */
class FakeFacebookProvider extends FakeNetworkProvider
{
    public function __construct()
    {
        parent::__construct('facebook', 'graph.facebook.test');
    }
}
