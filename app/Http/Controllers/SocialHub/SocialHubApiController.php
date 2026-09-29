<?php

declare(strict_types=1);

namespace App\Http\Controllers\SocialHub;

use App\Enums\SocialPlatform;
use App\Services\Social\SocialProviderRegistry;
use App\Services\Social\Support\ProviderDescriptor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON read model for the composer and the calendar.
 *
 * Only reading is exposed here. Publishing and account changes stay on the
 * web routes, where CSRF protection and the session apply.
 */
final class SocialHubApiController
{
    public function __construct(private readonly SocialProviderRegistry $registry) {}

    public function providers(Request $request, ?SocialProviderRegistry $unused = null): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (ProviderDescriptor $descriptor) => [
                'key' => $descriptor->key,
                'name' => $descriptor->name,
                'badge' => $descriptor->badge,
                'configured' => $descriptor->configured,
                'not_configured_reason' => $descriptor->notConfiguredReason,
                'requires_app_review' => $descriptor->requiresAppReview ?? false,
                'capabilities' => $descriptor->capabilities?->toArray(),
            ], $this->registry->describe()),
        ]);
    }

    public function platforms(Request $request): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (SocialPlatform $platform) => [
                'key' => $platform->value,
                'label' => $platform->label(),
                'max_caption' => $platform->maxCaptionLength(),
                'max_media' => $platform->maxMediaAttachments(),
                'native_scheduling' => $platform->supportsNativeScheduling(),
                'native_hashtags' => $platform->supportsNativeHashtags(),
                'native_mentions' => $platform->supportsNativeMentions(),
                'account_types' => $platform->accountTypes(),
            ], SocialPlatform::cases()),
        ]);
    }
}
