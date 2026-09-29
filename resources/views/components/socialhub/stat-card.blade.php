@props([
    'label',
    'value',
    'hint' => null,
    'tone' => 'default',
    'trend' => null,
    'denominator' => null,
])

@php
    $tones = [
        'default' => 'text-gray-800 dark:text-white/90',
        'brand' => 'text-brand-600 dark:text-brand-300',
        'success' => 'text-success-600 dark:text-success-500',
        'warning' => 'text-warning-600 dark:text-warning-500',
        'error' => 'text-error-600 dark:text-error-500',
    ];
@endphp

<div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex items-start justify-between gap-3">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
        @if ($trend !== null)
            <span @class([
                'shrink-0 rounded-full px-2 py-0.5 text-xs font-medium',
                'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' => $trend >= 0,
                'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500' => $trend < 0,
            ])>
                {{ $trend >= 0 ? '+' : '' }}{{ number_format($trend, 1) }}%
            </span>
        @endif
    </div>

    <p @class([
        'mt-2 text-2xl font-semibold tracking-tight',
        $tones[$tone] ?? $tones['default'],
    ])>
        {{ is_numeric($value) ? number_format((float) $value) : $value }}
    </p>

    @if ($denominator)
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Based on {{ $denominator }}</p>
    @endif

    @if ($hint)
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
</div>
