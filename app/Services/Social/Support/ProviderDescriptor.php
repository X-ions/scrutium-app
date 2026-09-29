<?php

declare(strict_types=1);

namespace App\Services\Social\Support;

use App\Services\Social\Contracts\ProviderCapabilities;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Config-level view of a provider, safe to hand to the UI or the doctor
 * command. Carries no secret material — only presence flags and reasons.
 */
final readonly class ProviderDescriptor implements Arrayable
{
    public function __construct(
        public string $key,
        public string $name,
        public string $badge,
        public ProviderCapabilities $capabilities,
        public bool $configured,
        public ?string $notConfiguredReason = null,
        public bool $requiresAppReview = false,
        public ?string $docsUrl = null,
        public bool $implemented = true,
        public ?string $class = null,
        public array $oauth = [],
    ) {}

    public function withImplementation(bool $implemented, ?string $class = null, ?string $reason = null): self
    {
        return new self(
            $this->key,
            $this->name,
            $this->badge,
            $this->capabilities,
            $this->configured,
            $reason ?? $this->notConfiguredReason,
            $this->requiresAppReview,
            $this->docsUrl,
            $implemented,
            $class ?? $this->class,
            $this->oauth,
        );
    }

    public function isUsable(): bool
    {
        return $this->implemented && $this->configured;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'badge' => $this->badge,
            'capabilities' => $this->capabilities->toArray(),
            'configured' => $this->configured,
            'not_configured_reason' => $this->notConfiguredReason,
            'requires_app_review' => $this->requiresAppReview,
            'docs_url' => $this->docsUrl,
            'implemented' => $this->implemented,
            'usable' => $this->isUsable(),
        ];
    }
}
