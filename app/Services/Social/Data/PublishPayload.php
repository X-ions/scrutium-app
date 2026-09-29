<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Content payload handed to a provider's publish method.
 *
 * `contentType` is derived from what is actually present; the provider asserts
 * its capabilities against it and rejects anything the platform cannot do.
 */
final readonly class PublishPayload implements Arrayable, JsonSerializable
{
    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const TYPE_CAROUSEL = 'carousel';

    public const TYPE_LINK = 'link';

    public const TYPE_STORY = 'story';

    public const TYPE_REEL = 'reel';

    /**
     * @param  list<string>  $mediaIds
     * @param  list<string>  $hashtags
     * @param  list<string>  $mentions
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $text = '',
        public array $mediaIds = [],
        public ?string $link = null,
        public ?string $title = null,
        public ?DateTimeInterface $scheduledAt = null,
        public ?string $firstComment = null,
        public array $hashtags = [],
        public array $mentions = [],
        public array $options = [],
        public ?string $contentType = null,
    ) {}

    public function contentType(): string
    {
        if ($this->contentType !== null && $this->contentType !== '') {
            return $this->contentType;
        }

        $count = count($this->mediaIds);

        if ($count > 1) {
            return self::TYPE_CAROUSEL;
        }

        if ($count === 1) {
            return $this->options['media_kind'] ?? self::TYPE_IMAGE;
        }

        if ($this->link !== null && $this->link !== '') {
            return self::TYPE_LINK;
        }

        return self::TYPE_TEXT;
    }

    public function isScheduled(): bool
    {
        return $this->scheduledAt !== null;
    }

    /**
     * Field shape accepted by a provider's feed endpoint: the caption, the
     * external link, and the media handle. A carousel expands to per-child
     * fields instead of a single handle.
     *
     * @return array<string, mixed>
     */
    public function attachedFields(): array
    {
        $fields = ['message' => $this->text];

        if ($this->link !== null && $this->link !== '') {
            $fields['link'] = $this->link;
        }

        $type = $this->contentType();

        if ($type === self::TYPE_CAROUSEL) {
            $fields['children'] = array_map(
                fn (string $mediaId): array => [
                    'message' => $this->text,
                    'media_fbid' => $mediaId,
                ],
                $this->mediaIds,
            );

            return $fields;
        }

        $mediaId = $this->mediaIds[0] ?? null;

        if ($mediaId !== null) {
            if ($type === self::TYPE_VIDEO) {
                $fields['video_id'] = $mediaId;
            } else {
                $fields['attached_media'] = json_encode([
                    'media_fbid' => $mediaId,
                ], JSON_THROW_ON_ERROR);
            }
        }

        $fields['media_kind'] = $type;

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'media_ids' => $this->mediaIds,
            'link' => $this->link,
            'title' => $this->title,
            'content_type' => $this->contentType(),
            'scheduled_at' => $this->scheduledAt?->format(DateTimeInterface::ATOM),
            'first_comment' => $this->firstComment,
            'hashtags' => $this->hashtags,
            'mentions' => $this->mentions,
            'options' => $this->options,
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
