<?php

declare(strict_types=1);

namespace App\Services\Social\OAuth;

use App\Services\Social\Capabilities\PlatformCapabilities;

/**
 * Intersects the scopes a feature needs with the scopes the provider declares
 * in config, so a provider can never be asked to request an unknown scope.
 */
final class Scopes
{
    /**
     * @param  list<string>|string  $requested
     * @param  list<string>|string  $declared
     * @return list<string>
     */
    public static function intersect(array|string $requested, array|string $declared): array
    {
        $declared = self::normalise($declared);

        return array_values(array_filter(
            self::normalise($requested),
            static fn (string $scope): bool => in_array($scope, $declared, true),
        ));
    }

    /**
     * Scopes that were requested but the provider does not declare.
     *
     * @param  list<string>|string  $requested
     * @param  list<string>|string  $declared
     * @return list<string>
     */
    public static function missing(array|string $requested, array|string $declared): array
    {
        $declared = self::normalise($declared);

        return array_values(array_filter(
            self::normalise($requested),
            static fn (string $scope): bool => ! in_array($scope, $declared, true),
        ));
    }

    /**
     * @param  list<string>|string  $granted
     * @param  list<string>|string  $required
     * @return list<string>
     */
    public static function notGranted(array|string $granted, array|string $required): array
    {
        $granted = self::normalise($granted);

        return array_values(array_filter(
            self::normalise($required),
            static fn (string $scope): bool => ! in_array($scope, $granted, true),
        ));
    }

    /**
     * @param  list<string>|string  $granted
     * @param  list<string>|string  $required
     */
    public static function covers(array|string $granted, array|string $required): bool
    {
        return self::notGranted($granted, $required) === [];
    }

    /**
     * @param  list<string>|string  $scopes
     * @return list<string>
     */
    public static function normalise(array|string $scopes): array
    {
        $items = is_string($scopes) ? preg_split('/[\s,]+/', $scopes) : $scopes;

        $normalised = [];

        foreach ($items ?: [] as $scope) {
            $scope = trim((string) $scope);

            if ($scope !== '') {
                $normalised[$scope] = true;
            }
        }

        return array_keys($normalised);
    }

    /**
     * Scopes the capability set requires for a platform, intersected with the
     * provider's declared allow-list.
     *
     * @param  list<string>|string  $declared
     * @return list<string>
     */
    public static function requiredFor(string $platform, array|string $declared): array
    {
        if (! PlatformCapabilities::supports($platform)) {
            return [];
        }

        return self::intersect(
            PlatformCapabilities::for($platform)->requiredScopes,
            $declared,
        );
    }
}
