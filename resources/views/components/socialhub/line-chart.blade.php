@props([
    'series' => [],
    'height' => 160,
    'label' => 'Views over time',
    'emptyMessage' => 'No data reported for this period yet.',
])

@php
    $points = collect($series)->map(fn ($row) => (float) (is_array($row) ? ($row['value'] ?? 0) : $row))->values();
    $max = $points->max() ?? 0;
    $count = $points->count();
    $width = 640;
@endphp

<div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $label }}</h3>
        @if ($max > 0)
            <span class="text-xs text-gray-500 dark:text-gray-400">Peak {{ number_format($max) }}</span>
        @endif
    </div>

    @if ($count < 2)
        <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">{{ $emptyMessage }}</p>
    @else
        <svg viewBox="0 0 {{ $width }} {{ $height }}" class="mt-4 w-full" role="img" aria-label="{{ $label }}">
            <line x1="0" y1="{{ $height - 1 }}" x2="{{ $width }}" y2="{{ $height - 1 }}"
                stroke="currentColor" class="text-gray-200 dark:text-gray-700" stroke-width="1" />
            <path d="{{ $points->map(function ($value, $index) use ($count, $width, $height, $max) {
                $x = $index === 0 ? 0 : ($index / ($count - 1)) * $width;
                $y = $max > 0 ? $height - (($value / $max) * ($height - 12)) - 6 : $height - 6;
                return ($index === 0 ? 'M' : 'L').round($x, 2).' '.round($y, 2);
            })->implode(' ') }}"
                fill="none" stroke="currentColor" class="text-brand-500" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    @endif
</div>
