@props([
    'status',
])

@php
    use App\Enums\PostStatus;
    use App\Enums\PostVariantStatus;

    // Accepts either enum, or the raw string a grouped query hands back.
    $enum = match (true) {
        $status instanceof PostVariantStatus => $status,
        $status instanceof PostStatus => PostVariantStatus::tryFrom($status->value) ?? PostVariantStatus::Pending,
        default => PostVariantStatus::tryFrom((string) $status) ?? PostStatus::tryFrom((string) $status),
    };

    if ($enum instanceof PostStatus) {
        $enum = match ($enum) {
            PostStatus::Published => PostVariantStatus::Published,
            PostStatus::Failed => PostVariantStatus::Failed,
            PostStatus::Cancelled => PostVariantStatus::Cancelled,
            PostStatus::Scheduled, PostStatus::Publishing, PostStatus::Queued => PostVariantStatus::Publishing,
            default => PostVariantStatus::Pending,
        };
    }
@endphp

<span @class([
    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
    $enum->badgeColor(),
])>
    <span class="h-1.5 w-1.5 rounded-full bg-current opacity-70" aria-hidden="true"></span>
    {{ $enum->label() }}
</span>
