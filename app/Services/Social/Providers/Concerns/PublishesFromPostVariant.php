<?php

declare(strict_types=1);

namespace App\Services\Social\Providers\Concerns;

use App\Models\PostVariant;
use App\Services\Social\Data\PublishPayload;

/**
 * Builds the provider-agnostic publish payload from a stored post variant.
 *
 * Every provider's `publishPost()` is the same translation, so it lives here
 * rather than being copied six times. Per-network behaviour is driven entirely
 * by `platform_specific`, which is where `content_type`, `first_comment`, and
 * network-specific options already live.
 */
trait PublishesFromPostVariant
{
    protected function payloadFromVariant(PostVariant $variant): PublishPayload
    {
        $options = (array) ($this->attribute($variant, 'platform_specific') ?? []);

        return new PublishPayload(
            text: (string) ($this->attribute($variant, 'caption') ?? ''),
            mediaIds: (array) ($this->attribute($variant, 'media_ids') ?? []),
            link: isset($options['link']) ? (string) $options['link'] : null,
            title: isset($options['title']) ? (string) $options['title'] : null,
            scheduledAt: $this->readDate($variant, 'scheduled_at'),
            firstComment: (string) ($options['first_comment'] ?? '') ?: null,
            hashtags: (array) ($this->attribute($variant, 'hashtags') ?? []),
            mentions: (array) ($this->attribute($variant, 'mentions') ?? []),
            options: $options,
            contentType: (string) ($options['content_type'] ?? '') ?: null,
        );
    }

    /**
     * Accepts either a real PublishPayload or a loose array, so the composer and
     * the queue job can both call `createPost()` with what they hold.
     */
    protected function payloadFromRequest(array $payload): PublishPayload
    {
        $existing = $payload['payload'] ?? null;

        if ($existing instanceof PublishPayload) {
            return $existing;
        }

        return new PublishPayload(
            text: (string) ($payload['text'] ?? $payload['caption'] ?? ''),
            mediaIds: (array) ($payload['media_ids'] ?? []),
            link: isset($payload['link']) ? (string) $payload['link'] : null,
            title: isset($payload['title']) ? (string) $payload['title'] : null,
            scheduledAt: isset($payload['scheduled_at']) ? $this->toDate($payload['scheduled_at']) : null,
            firstComment: (string) ($payload['first_comment'] ?? '') ?: null,
            hashtags: (array) ($payload['hashtags'] ?? []),
            mentions: (array) ($payload['mentions'] ?? []),
            options: (array) ($payload['options'] ?? $payload['platform_specific'] ?? []),
            contentType: isset($payload['content_type']) ? (string) $payload['content_type'] : null,
        );
    }

    /**
     * A media entry is a publicly reachable URL the platform can fetch itself, or
     * a provider-side asset id from a previous `uploadMedia()` call.
     */
    protected function mediaUrlOf(array $options, int $index = 0): ?string
    {
        $urls = (array) ($options['media_urls'] ?? $options['media_url'] ?? []);

        if ($urls === []) {
            return null;
        }

        $url = is_array($urls) ? ($urls[$index] ?? null) : $urls;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
