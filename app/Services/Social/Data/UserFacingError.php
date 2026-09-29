<?php

declare(strict_types=1);

namespace App\Services\Social\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Provider-agnostic error contract rendered on a post variant status card.
 *
 * `userMessage` is written for a social media manager, `technicalMessage` is for
 * support tickets and logs, `remediation` is the single next action.
 */
final readonly class UserFacingError implements Arrayable, JsonSerializable
{
    /**
     * Keys whose values are dropped from `context` before it is stored. A
     * caller assembling context from a provider payload must not be able to
     * carry a token into an object that is safe to log.
     *
     * @var list<string>
     */
    private const REDACTED_CONTEXT_KEYS = [
        'access_token',
        'refresh_token',
        'id_token',
        'client_secret',
        'authorization',
        'code_verifier',
        'code',
        'password',
        'token',
        'secret',
        'app_secret',
    ];

    /**
     * @var array<string, mixed>
     */
    public readonly array $context;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $code,
        public string $userMessage,
        public string $technicalMessage,
        public bool $retryable = false,
        public ?string $remediation = null,
        array $context = [],
    ) {
        $this->context = self::redactContext($context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function redactContext(array $context): array
    {
        $redacted = [];

        foreach ($context as $key => $value) {
            $redacted[$key] = in_array(strtolower((string) $key), self::REDACTED_CONTEXT_KEYS, true)
                ? '[redacted]'
                : (is_array($value) ? self::redactContext($value) : $value);
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(
        string $code,
        string $userMessage,
        string $technicalMessage,
        bool $retryable = false,
        ?string $remediation = null,
        array $context = [],
    ): self {
        return new self($code, $userMessage, $technicalMessage, $retryable, $remediation, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'user_message' => $this->userMessage,
            'technical_message' => $this->technicalMessage,
            'retryable' => $this->retryable,
            'remediation' => $this->remediation,
            'context' => $this->context,
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
