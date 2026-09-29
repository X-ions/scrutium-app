<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Implicit model binding resolves a route parameter by *variable name*.
 *
 * When a route declares `{socialAccount}` but the controller method takes
 * `SocialAccount $account`, Laravel finds no `account` parameter and quietly
 * hands the controller a brand-new empty model. Nothing 404s and nothing
 * throws: the policy compares a null tenant id against a real one, so the
 * route simply never works — and a later edit could turn that into a genuine
 * authorization hole.
 */
it('binds every model route parameter to a matching controller argument', function (): void {
    $mismatches = [];

    foreach (Route::getRoutes() as $route) {
        $action = $route->getActionName();

        if (! str_contains($action, 'Controller')) {
            continue;
        }

        [$class, $method] = array_pad(explode('@', $action), 2, null);

        if ($class === null || $method === null || ! class_exists($class) || ! method_exists($class, $method)) {
            continue;
        }

        $reflection = new ReflectionMethod($class, $method);
        $argumentNames = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $reflection->getParameters(),
        );

        foreach ($route->parameterNames() as $parameter) {
            if (! isModelParameter($reflection, $parameter)) {
                continue;
            }

            if (in_array($parameter, $argumentNames, true) || in_array(Str::camel($parameter), $argumentNames, true)) {
                continue;
            }

            $mismatches[] = sprintf(
                '%s::%s() has no argument for the {%s} parameter',
                class_basename($class),
                $method,
                $parameter,
            );
        }
    }

    expect($mismatches)->toBe([]);
});

/**
 * A parameter only matters if the controller declared a matching name for it
 * and that name is type-hinted as an Eloquent model.
 */
function isModelParameter(ReflectionMethod $reflection, string $parameter): bool
{
    foreach ($reflection->getParameters() as $argument) {
        if ($argument->getName() !== $parameter && Str::camel($parameter) !== $argument->getName()) {
            continue;
        }

        $type = $argument->getType();

        return $type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Illuminate\Database\Eloquent\Model::class);
    }

    return false;
}
