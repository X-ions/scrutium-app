<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A comment as returned by a provider, used both for reading and for replying.
 */
final readonly class ProviderComment implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $provider,
        public string $providerCommentId,
        public string $providerPostId,
        public string $content,
        public ?string $parentProviderCommentId = null,
        public ?string $authorProviderId = null,
        public ?string $authorUsername = null,
        public ?string $authorDisplayName = null,
        public ?string $authorAvatarUrl = null,
        public int $likeCount = 0,
        public int $replyCount = 0,
        public ?DateTimeInterface $createdAt = null,
        public array $raw = [],
    ) {}

    public function isReply(): bool
    {
        return $this->parentProviderCommentId !== null && $this->parentProviderCommentId !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_comment_id' => $this->providerCommentId,
            'provider_post_id' => $this->providerPostId,
            'parent_provider_comment_id' => $this->parentProviderCommentId,
            'content' => $this->content,
            'author_provider_id' => $this->authorProviderId,
            'author_username' => $this->authorUsername,
            'author_display_name' => $this->authorDisplayName,
            'author_avatar_url' => $this->authorAvatarUrl,
            'like_count' => $this->likeCount,
            'reply_count' => $this->replyCount,
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
