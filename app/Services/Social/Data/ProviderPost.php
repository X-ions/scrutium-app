<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A post as it exists on the platform after (or before) publishing.
 */
final readonly class ProviderPost implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $attachedMediaIds
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $provider,
        public string $providerPostId,
        public ?string $permalink = null,
        public ?string $text = null,
        public ?string $contentType = null,
        public array $attachedMediaIds = [],
        public ?DateTimeInterface $publishedAt = null,
        public ?DateTimeInterface $createdAt = null,
        public array $raw = [],
    ) {}

    public function isPublished(): bool
    {
        return $this->providerPostId !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_post_id' => $this->providerPostId,
            'permalink' => $this->permalink,
            'text' => $this->text,
            'content_type' => $this->contentType,
            'attached_media_ids' => $this->attachedMediaIds,
            'published_at' => $this->publishedAt?->format(DateTimeInterface::ATOM),
            'created_at' => $this->createdAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
