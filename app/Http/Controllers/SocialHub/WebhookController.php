<?php

declare(strict_types=1);

namespace App\Http\Controllers\SocialHub;

use App\Services\Engagement\WebhookIngestService;
use App\Services\Social\Data\VerifiedWebhookRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provider webhook endpoint.
 *
 * This route sits outside the web session and CSRF protection because the
 * caller is a platform, not a browser. In exchange the raw body is verified
 * against the provider's own signature before anything is read out of it, and
 * every event id is recorded so a redelivery is a no-op.
 */
final class WebhookController
{
    public function __construct(private readonly WebhookIngestService $ingest) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $raw = $request->getContent();
        $payload = json_decode($raw, true);

        $verified = new VerifiedWebhookRequest(
            provider: $provider,
            rawBody: $raw,
            payload: is_array($payload) ? $payload : [],
            headers: $request->headers->all(),
            receivedAt: time(),
        );

        try {
            $result = $this->ingest->ingest($verified);
        } catch (Throwable $exception) {
            Log::error('socialhub.webhook.ingest_failed', [
                'provider' => $provider,
                'exception' => $exception::class,
            ]);

            return response()->json(['received' => false], 500);
        }

        if (! $result->shouldRespondOk()) {
            return response()->json([
                'received' => false,
                'reason' => $result->reason,
            ], $result->httpStatus());
        }

        return response()->json([
            'received' => true,
            'outcome' => $result->outcome,
        ], 200);
    }
}
