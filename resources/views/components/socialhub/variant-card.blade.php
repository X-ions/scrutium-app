@props([
    'variant',
    'canRetry' => false,
])

@php
    use App\Enums\PostVariantStatus;

    $status = $variant->statusEnum();
    $failed = $status === PostVariantStatus::Failed;
@endphp

<div @class([
    'rounded-2xl border p-4 dark:bg-white/[0.03]',
    'border-error-200 bg-error-50/40 dark:border-error-500/30 dark:bg-error-500/5' => $failed,
    'border-success-200 bg-success-50/40 dark:border-success-500/30 dark:bg-success-500/5' => $status === PostVariantStatus::Published,
    'border-gray-200 bg-white dark:border-gray-800' => ! $failed && $status !== PostVariantStatus::Published,
])>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <x-socialhub.platform-chip :provider="$variant->provider" size="md" />
        <x-socialhub.status-badge :status="$status" />
    </div>

    <dl class="mt-3 space-y-1 text-xs text-gray-600 dark:text-gray-300">
        @if ($variant->published_at)
            <div class="flex justify-between gap-2">
                <dt>Published</dt>
                <dd class="text-gray-500 dark:text-gray-400">{{ $variant->published_at->diffForHumans() }}</dd>
            </div>
        @elseif ($variant->scheduled_at)
            <div class="flex justify-between gap-2">
                <dt>Scheduled</dt>
                <dd class="text-gray-500 dark:text-gray-400">{{ $variant->scheduled_at->format('j M Y, H:i') }}</dd>
            </div>
        @endif

        @if ($variant->retry_count > 0)
            <div class="flex justify-between gap-2">
                <dt>Attempts</dt>
                <dd class="text-gray-500 dark:text-gray-400">{{ $variant->retry_count }}</dd>
            </div>
        @endif
    </dl>

    @if ($failed && $variant->error_message)
        <div class="mt-3 rounded-lg bg-white p-3 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-200">
            <p class="font-medium text-error-700 dark:text-error-400">Publishing failed</p>
            <p class="mt-1 leading-relaxed">{{ $variant->error_message }}</p>
            @if ($canRetry)
                <p class="mt-2 text-gray-500 dark:text-gray-400">
                    Reconnect or fix the content, then retry. Other networks on this post are unaffected.
                </p>
            @endif
        </div>
    @endif

    @if ($variant->provider_post_url)
        <a href="{{ $variant->provider_post_url }}" target="_blank" rel="noopener noreferrer"
            class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-300">
            View on {{ $variant->provider?->label() }}
            <span aria-hidden="true" class="rtl:rotate-180">→</span>
        </a>
    @endif
</div>
