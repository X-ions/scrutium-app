<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Follower/audience totals plus the growth window the provider returned.
 */
final readonly class FollowerStats implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, int>  $breakdown
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $provider,
        public string $providerAccountId,
        public int $total,
        public array $breakdown = [],
        public ?int $change = null,
        public ?DateTimeInterface $asOf = null,
        public array $raw = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_account_id' => $this->providerAccountId,
            'total' => $this->total,
            'breakdown' => $this->breakdown,
            'change' => $this->change,
            'as_of' => $this->asOf?->format(DateTimeInterface::ATOM),
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
