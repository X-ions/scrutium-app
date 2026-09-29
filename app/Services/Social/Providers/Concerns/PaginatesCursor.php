<?php

declare(strict_types=1);

namespace App\Services\Social\Providers\Concerns;

use App\Exceptions\Social\ProviderApiException;
use Illuminate\Http\Client\Response;

/**
 * Cursor/offset pagination for provider list endpoints.
 *
 * Each platform encodes its cursor differently, so the provider supplies a tiny
 * extractor closure. The concern handles walking, de-duplication, and the
 * per-request ceiling so a runaway cursor cannot loop forever.
 */
trait PaginatesCursor
{
    /**
     * Walk every page, yielding the decoded body of each response.
     *
     * @template T
     *
     * @param  callable(?string): Response  $fetch
     * @param  callable(array<string, mixed>): ?string  $nextCursor  Returns null when exhausted.
     * @return list<array<string, mixed>>
     */
    protected function paginate(callable $fetch, callable $nextCursor, ?int $maxPages = null): array
    {
        $maxPages ??= (int) config('socialhub.pagination.max_pages', 25);
        $pages = [];
        $cursor = null;
        $seen = [];

        for ($i = 0; $i < $maxPages; $i++) {
            $response = $fetch($cursor);

            if (! $response->successful()) {
                $this->assertNotRateLimited($response);
                $this->throwPaginationFailure($response);
            }

            $body = (array) $response->json();
            $pages[] = $body;

            $cursor = $nextCursor($body);

            if ($cursor === null || $cursor === '') {
                return $pages;
            }

            // A provider that repeats its cursor would otherwise spin forever.
            if (isset($seen[$cursor])) {
                return $pages;
            }

            $seen[$cursor] = true;
        }

        return $pages;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  callable(array<string, mixed>): mixed  $extract
     * @return list<mixed>
     */
    protected function collectItems(array $pages, callable $extract): array
    {
        $items = [];

        foreach ($pages as $page) {
            foreach ((array) $extract($page) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Meta's after-cursor pagination, encoded in `paging.cursors.after`.
     *
     * @param  array<string, mixed>  $body
     */
    protected function cursorFrom(array $body): ?string
    {
        $after = $body['paging']['cursors']['after'] ?? null;

        if (is_string($after) && $after !== '') {
            return $after;
        }

        $next = $body['paging']['next'] ?? null;

        if (! is_string($next) || $next === '') {
            return null;
        }

        $query = parse_url($next, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $params);

        return is_string($params['after'] ?? null) && $params['after'] !== '' ? $params['after'] : null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    protected function itemsFrom(array $body, string $key = 'data'): array
    {
        $items = $body[$key] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * @throws ProviderApiException
     */
    abstract protected function throwPaginationFailure(Response $response): ProviderApiException;
}
