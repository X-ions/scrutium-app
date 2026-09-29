@props([
    'provider',
    'label' => null,
    'size' => 'sm',
])

@php
    use App\Enums\SocialPlatform;

    $enum = $provider instanceof SocialPlatform ? $provider : SocialPlatform::tryFrom((string) $provider);
    $label ??= $enum?->label() ?? ucfirst((string) $provider);
    $initials = mb_substr(preg_replace('/[^A-Za-z0-9 ]/', '', $label) ?: '', 0, 2);
@endphp

<span @class([
    'inline-flex items-center gap-1.5 rounded-full font-medium',
    $enum?->badgeColor() ?? 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80',
    'px-2 py-0.5 text-[11px]' => $size === 'sm',
    'px-2.5 py-1 text-xs' => $size === 'md',
])>
    <span class="font-semibold uppercase tracking-wide">{{ $initials }}</span>
    <span>{{ $label }}</span>
</span>
