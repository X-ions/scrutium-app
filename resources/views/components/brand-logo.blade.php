@props([
    'size' => 'md',
    'showWordmark' => true,
    'wordmarkClass' => 'text-gray-900 dark:text-white',
])

@php
    $box = match ($size) {
        'sm' => 'h-8 w-8',
        'lg' => 'h-12 w-12',
        default => 'h-10 w-10',
    };
    $text = match ($size) {
        'sm' => 'text-base',
        'lg' => 'text-2xl',
        default => 'text-xl',
    };
@endphp

<a href="{{ url('/') }}" {{ $attributes->class('inline-flex items-center gap-3') }}>
    <img src="{{ asset('images/logo/logo.png') }}" alt="Scrutium"
        class="{{ $box }} rounded-xl object-cover shadow-sm bg-white" />
    @if ($showWordmark)
        <span class="{{ $text }} font-semibold tracking-tight {{ $wordmarkClass }}">Scrutium</span>
    @endif
</a>
