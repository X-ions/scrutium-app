<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Resolves the product-tour step definitions for the current viewer.
 *
 * Steps are filtered by audience so a read-only role is never handed a step
 * that tells it to click a button the operate/admin middleware will refuse.
 */
class Tour
{
    /**
     * @return array{steps: list<array<string, mixed>>, storage_key: string}
     */
    public static function for(?User $user = null): array
    {
        $definitions = config('tour.steps', []);

        $steps = [];

        foreach ($definitions as $step) {
            if (! self::isVisible($step['audience'] ?? 'any', $user)) {
                continue;
            }

            $routeName = $step['route'] ?? null;
            $href = is_string($routeName) && Route::has($routeName)
                ? route($routeName)
                : null;

            // The CTA reads "Open campaigns" — pointless when the viewer is
            // already standing on that page. Comparing server-side is more
            // reliable than parsing URLs in the browser.
            $onThisPage = $href !== null
                && parse_url($href, PHP_URL_PATH) === request()->getPathInfo();

            $steps[] = [
                'id' => $step['id'],
                'title' => $step['title'],
                'body' => $step['body'],
                'tip' => $step['tip'] ?? null,
                'route' => $routeName,
                'href' => $href,
                'onThisPage' => $onThisPage,
                'cta' => $step['cta'] ?? null,
                'audience' => $step['audience'] ?? 'any',
            ];
        }

        return [
            'steps' => $steps,
            'storage_key' => config('tour.storage_key', 'scrutium.tour.completed'),
        ];
    }

    /**
     * Current route name, so the tour knows when a step needs a page change.
     */
    public static function currentRoute(): ?string
    {
        return request()->route()?->getName();
    }

    public static function shouldAutoStart(): bool
    {
        return request()->routeIs('dashboard');
    }

    private static function isVisible(string $audience, ?User $user): bool
    {
        return match ($audience) {
            'operator' => (bool) $user?->canOperate(),
            'admin' => (bool) $user?->canManageWorkspace(),
            default => true,
        };
    }
}
