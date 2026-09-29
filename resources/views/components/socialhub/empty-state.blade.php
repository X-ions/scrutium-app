@props([
    'title',
    'description' => null,
    'actionLabel' => null,
    'actionUrl' => null,
    'icon' => 'pages',
])

<div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50/60 p-10 text-center dark:border-gray-700 dark:bg-white/[0.02]">
    <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-white text-gray-400 shadow-sm dark:bg-white/5 dark:text-gray-500">
        {!! App\Helpers\MenuHelper::getIconSvg($icon) !!}
    </span>

    <h3 class="mt-4 text-base font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h3>

    @if ($description)
        <p class="mx-auto mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif

    @if ($actionLabel && $actionUrl)
        <a href="{{ $actionUrl }}"
            class="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
            {{ $actionLabel }}
        </a>
    @endif
</div>
