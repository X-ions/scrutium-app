<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

/**
 * The stand-in the registry resolves for the `instagram` provider key.
 */
class FakeInstagramProvider extends FakeNetworkProvider
{
    public function __construct()
    {
        parent::__construct('instagram', 'graph.instagram.test');
    }
}
