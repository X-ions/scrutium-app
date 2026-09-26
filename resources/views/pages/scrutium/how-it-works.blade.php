@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-4xl">

        <x-common.page-breadcrumb pageTitle="How it works" />

        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                The short version of everything you need to run a campaign in Scrutium.
            </p>

            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-brand-600 dark:bg-brand-500 dark:hover:bg-brand-400"
                x-data
                @click="window.dispatchEvent(new CustomEvent('scrutium:open-tour'))"
            >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9L12 3z"
                          stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                </svg>
                Play the tour
            </button>
        </div>

        <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 dark:bg-brand-500/10"
                      aria-hidden="true">
                    <svg class="h-5 w-5 text-brand-600 dark:text-brand-400" viewBox="0 0 24 24" fill="none">
                        <path d="M4 6.5A2.5 2.5 0 016.5 4h11A2.5 2.5 0 0120 6.5v11a2.5 2.5 0 01-2.5 2.5h-11A2.5 2.5 0 014 17.5v-11z"
                              stroke="currentColor" stroke-width="1.6" />
                        <path d="M4 8h16M8 4v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                </span>
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white/90">The loop, end to end</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Brief a campaign, staff it with vetted creators, track deliverables through
                        verification, then read the outcome in performance, reports and alerts.
                    </p>
                </div>
            </div>
        </div>

        <ol class="space-y-3">
            @foreach ($steps as $position => $step)
                <li class="rounded-2xl border border-gray-200 bg-white p-5 transition hover:border-brand-200 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
                    <div class="flex items-start gap-4">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-sm font-semibold text-brand-600 dark:bg-brand-500/10 dark:text-brand-400"
                              aria-hidden="true"
                        >{{ str_pad((string) ($position + 1), 2, '0', STR_PAD_LEFT) }}</span>

                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white/90">
                                {{ $step['title'] }}
                            </h2>

                            <p class="mt-1.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                                {{ $step['body'] }}
                            </p>

                            @if ($step['tip'])
                                <div class="mt-2 flex gap-2 rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-600 dark:bg-white/[0.03] dark:text-gray-400">
                                    <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400 dark:text-gray-500" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle cx="12" cy="12" r="9.25" stroke="currentColor" stroke-width="1.6" />
                                        <path d="M12 11v5.5M12 7.75v.75" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                    </svg>
                                    <span>{{ $step['tip'] }}</span>
                                </div>
                            @endif

                            @if ($step['href'] && $step['cta'] && ! $step['onThisPage'])
                                <a
                                    href="{{ $step['href'] }}"
                                    class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 transition hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300"
                                >
                                    {{ $step['cta'] }}
                                    <span aria-hidden="true" class="rtl:rotate-180">&rarr;</span>
                                </a>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>

        <p class="mt-6 text-center text-xs text-gray-400 dark:text-gray-500">
            Steps shown for your role. The guided tour is available any time from the guide button in the header.
        </p>
    </div>
@endsection
