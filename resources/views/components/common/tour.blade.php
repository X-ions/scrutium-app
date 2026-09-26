@props([
    'steps' => [],
    'autoStart' => false,
    'storageKey' => 'scrutium.tour.completed',
    'currentRoute' => null,
])

<div
    x-data="scrutiumTour(@js($steps), { storageKey: @js($storageKey), autoStart: @js($autoStart), currentRoute: @js($currentRoute) })"
    x-ref="dialog"
    tabindex="-1"
    class="focus:outline-none"
    x-cloak
>
    <template x-if="open">
        {{-- Sits above the sticky header (z-99999) and sidebar, so the whole
             page is inert while the tour is open. --}}
        <div class="fixed inset-0 z-999999" role="dialog" aria-modal="true" aria-labelledby="scrutium-tour-title">
            {{-- Scrim. Also the click-away target when a step has no highlight. --}}
            <div
                class="absolute inset-0 bg-gray-900/50 dark:bg-gray-950/60"
                x-show="!spot"
                @click="close()"
            ></div>

            {{-- A highlighted step is dimmed by the spotlight ring instead, so
                 this layer stays transparent and lets the target stay clickable. --}}
            <div
                class="pointer-events-none absolute inset-0"
                x-show="spot"
            ></div>

            {{-- Step card --}}
            <div
                x-show="open"
                class="fixed w-[min(380px,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-700 dark:bg-gray-900"
                :style="cardStyle()"
                :class="spot ? '' : 'inset-auto start-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 rtl:translate-x-1/2'"
            >
                <div class="h-1 w-full bg-gray-100 dark:bg-white/5">
                    <div
                        class="h-1 rounded-e-full bg-brand-500 transition-all duration-300 dark:bg-brand-400"
                        role="progressbar"
                        :aria-valuenow="index + 1"
                        :aria-valuemin="1"
                        :aria-valuemax="total"
                        :style="`width: ${progress}%`"
                    ></div>
                </div>

                <div class="p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-xl dark:bg-brand-500/10"
                                aria-hidden="true"
                                x-text="step?.icon"
                            ></span>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-400"
                                   x-text="`Step ${index + 1} of ${total}`"
                                ></p>
                                <h2 id="scrutium-tour-title"
                                    class="text-base font-semibold text-gray-900 dark:text-white/90"
                                    x-text="step?.title"
                                ></h2>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5 dark:hover:text-gray-200"
                            @click="close()"
                            aria-label="Close tour"
                        >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                            </svg>
                        </button>
                    </div>

                    <p class="mt-4 text-sm leading-relaxed text-gray-600 dark:text-gray-300" x-text="step?.body"></p>

                    <template x-if="step?.tip">
                        <p class="mt-3 flex gap-2 rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-600 dark:bg-white/[0.03] dark:text-gray-400">
                            <span aria-hidden="true">💡</span>
                            <span x-text="step?.tip"></span>
                        </p>
                    </template>

                    <template x-if="targetMissing">
                        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                            That part of the page is not on screen right now.
                        </p>
                    </template>

                    <div class="mt-5 flex items-center justify-between gap-3">
                        <button
                            type="button"
                            class="text-sm font-medium text-gray-500 transition hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-100"
                            @click="close()"
                        >
                            Skip
                        </button>

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                                @click="previous()"
                                :disabled="isFirst"
                            >
                                Back
                            </button>

                            <button
                                type="button"
                                class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 dark:bg-brand-500 dark:hover:bg-brand-400"
                                @click="next()"
                            >
                                <span x-text="isLast ? 'Got it' : 'Next'"></span>
                            </button>
                        </div>
                    </div>

                    <template x-if="step?.cta && step?.href && step.onThisPage !== true">
                        <a
                            :href="step.href"
                            class="mt-3 flex items-center justify-center gap-1.5 rounded-lg bg-gray-100 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-200 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10"
                        >
                            <span x-text="step.cta"></span>
                            <span aria-hidden="true" class="rtl:rotate-180">→</span>
                        </a>
                    </template>
                </div>

                <div class="flex items-center justify-center gap-0.5 border-t border-gray-100 px-4 py-2 dark:border-gray-800">
                    <template x-for="(dot, dotIndex) in steps" :key="dot.id">
                        <button
                            type="button"
                            class="flex h-6 w-6 items-center justify-center"
                            @click="goTo(dotIndex)"
                            :aria-label="`Go to step ${dotIndex + 1}: ${dot.title}`"
                            :aria-current="dotIndex === index ? 'step' : null"
                        >
                            <span
                                class="h-1.5 rounded-full transition-all"
                                :class="dotIndex === index ? 'w-5 bg-brand-500 dark:bg-brand-400' : 'w-1.5 bg-gray-300 dark:bg-gray-600'"
                            ></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>
