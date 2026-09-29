@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @php
        $days = $view === 'day' ? 1 : ($view === 'week' ? 7 : 0);
        $cursor = $days > 0 ? $start->copy() : $start->copy();
        $cursorEnd = $days > 0 ? $end->copy() : $end->copy()->endOfMonth()->startOfMonth()->subDay();
        $monthLabel = $view === 'day' ? $start->format('j M Y') : ($view === 'week' ? $start->format('j M').' – '.$end->format('j M Y') : $start->format('F Y'));
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <a href="{{ route('socialhub.calendar', ['view' => $view, 'date' => $previous]) }}"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">
                <span aria-hidden="true" class="rtl:rotate-180">←</span>
                <span class="sr-only">Previous</span>
            </a>
            <a href="{{ route('socialhub.calendar', ['view' => 'month', 'date' => $today->toDateString()]) }}"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Today</a>
            <a href="{{ route('socialhub.calendar', ['view' => $view, 'date' => $next]) }}"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">
                <span aria-hidden="true" class="rtl:rotate-180">→</span>
                <span class="sr-only">Next</span>
            </a>
            <h2 class="ms-2 text-base font-semibold text-gray-800 dark:text-white/90">{{ $monthLabel }}</h2>
        </div>

        <div class="flex rounded-lg border border-gray-200 p-0.5 dark:border-gray-700" role="tablist" aria-label="Calendar view">
            @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $key => $caption)
                <a href="{{ route('socialhub.calendar', ['view' => $key, 'date' => $anchor->toDateString()]) }}"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium',
                        'bg-brand-600 text-white' => $view === $key,
                        'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $view !== $key,
                    ])
                    role="tab"
                    aria-selected="{{ $view === $key ? 'true' : 'false' }}">{{ $caption }}</a>
            @endforeach
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @if ($view === 'month')
            <div class="grid grid-cols-7 border-b border-gray-200 bg-gray-50 text-center text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:bg-white/5 dark:text-gray-400">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName)
                    <div class="px-2 py-2">{{ $dayName }}</div>
                @endforeach
            </div>
        @endif

        <div @class([
            'grid gap-px bg-gray-100 dark:bg-gray-800' => $view === 'month',
            'grid-cols-7' => $view === 'month',
            'space-y-2 p-4' => $view !== 'month',
        ])>
            @if ($view === 'month')
                @php $gridStart = $start->copy()->startOfWeek(); @endphp
                @for ($i = 0; $i < 42; $i++)
                    @php
                        $day = $gridStart->copy()->addDays($i);
                        $inMonth = $day->month === $start->month;
                        $dayItems = $byDay[$day->toDateString()] ?? [];
                    @endphp
                    <div @class([
                        'min-h-[7rem] bg-white p-2 dark:bg-white/[0.03]',
                        'opacity-40' => ! $inMonth,
                        'bg-brand-50/60 dark:bg-brand-500/5' => $day->isSameDay($today),
                    ])>
                        <p @class([
                            'mb-1 text-xs font-medium',
                            'text-brand-700 dark:text-brand-300' => $day->isSameDay($today),
                            'text-gray-500 dark:text-gray-400' => ! $day->isSameDay($today),
                        ])>{{ $day->day }}</p>

                        <ul class="space-y-1">
                            @foreach (array_slice($dayItems, 0, 3) as $item)
                                <li>
                                    <a href="{{ $item['post_id'] ? route('socialhub.posts.show', $item['post_id']) : '#' }}"
                                        class="block truncate rounded px-1.5 py-0.5 text-[11px] {{ $item['badge'] }} hover:underline">
                                        {{ $item['title'] }}
                                    </a>
                                </li>
                            @endforeach
                            @if (count($dayItems) > 3)
                                <li class="px-1.5 text-[11px] text-gray-500 dark:text-gray-400">+{{ count($dayItems) - 3 }} more</li>
                            @endif
                        </ul>
                    </div>
                @endfor
            @else
                @php $span = $view === 'day' ? 1 : 7; @endphp
                @for ($i = 0; $i < $span; $i++)
                    @php
                        $day = $cursor->copy()->addDays($i);
                        $dayItems = $byDay[$day->toDateString()] ?? [];
                    @endphp
                    <div @class([
                        'rounded-xl border p-3',
                        'border-brand-300 bg-brand-50/50 dark:border-brand-500/40 dark:bg-brand-500/5' => $day->isSameDay($today),
                        'border-gray-200 dark:border-gray-700' => ! $day->isSameDay($today),
                    ])>
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ $day->format('D j M') }}
                        </p>
                        <ul class="mt-2 space-y-1">
                            @forelse ($dayItems as $item)
                                <li>
                                    <a href="{{ route('socialhub.posts.show', $item['post_id']) }}"
                                        class="block truncate rounded-lg border border-gray-200 px-2 py-1.5 text-xs dark:border-gray-700">
                                        <span class="block font-medium text-gray-800 dark:text-white/90">{{ $item['title'] }}</span>
                                        <span class="mt-0.5 flex items-center gap-1.5 text-[11px] text-gray-500 dark:text-gray-400">
                                            <x-socialhub.platform-chip :provider="$item['provider']" />
                                            {{ $item['human'] }}
                                        </span>
                                    </a>
                                </li>
                            @empty
                                <li class="text-xs text-gray-400 dark:text-gray-500">Nothing scheduled</li>
                            @endforelse
                        </ul>
                    </div>
                @endfor
            @endif
        </div>
    </div>

    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        Drafts are shown on the day they were created. Moving a post to a new time is done from the post page.
    </p>
@endsection
