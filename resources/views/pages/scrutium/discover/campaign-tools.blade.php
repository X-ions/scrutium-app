@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Campaign tools" />

    <div x-data='campaignTools({ createUrl: @js(route("campaigns.create")), applyUrl: @js(route("campaign-tools.apply")), currency: @js(auth()->user()->tenant->currency), creators: @js($creators) })' class="space-y-6 pb-10">
        <section class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-950 via-brand-900 to-brand-700 p-6 text-white shadow-theme-lg md:p-9">
            <div class="pointer-events-none absolute -end-12 -top-24 h-72 w-72 rounded-full bg-blue-light-400/15 blur-3xl" aria-hidden="true"></div>
            <div class="relative max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-light-200">Campaign workspace</p>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight md:text-4xl">Plan sharper creator campaigns.</h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-white/75 md:text-base">Build a structured brief, pressure-test your budget, and make campaign requirements clear before launch.</p>
                <div class="mt-6 flex flex-wrap gap-2 text-xs font-medium text-white/85">
                    <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1.5">Brief builder</span>
                    <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1.5">Budget estimator</span>
                    <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1.5">Content packages</span>
                    <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1.5">Audience matcher</span>
                    <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1.5">Contract clauses</span>
                    <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1.5">Disclosure check</span>
                </div>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <div class="flex items-start gap-3">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2.5 11.8 8l5.7 2-5.7 2L10 17.5 8.2 12 2.5 10l5.7-2L10 2.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="m15.5 2 .7 2.3 2.3.7-2.3.7-.7 2.3-.7-2.3-2.3-.7 2.3-.7.7-2.3Z" fill="currentColor"/></svg>
                    </span>
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Campaign brief builder</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Turn campaign inputs into a clear, editable creator brief.</p>
                    </div>
                </div>

                <div class="mt-5 rounded-xl border border-blue-light-200 bg-blue-light-25 px-4 py-3 text-xs leading-5 text-blue-light-800 dark:border-blue-light-500/20 dark:bg-blue-light-500/10 dark:text-blue-light-200">
                    This creates a structured starter draft from your inputs. No external AI service is connected, so review and tailor the copy before sharing it.
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Product or brand
                        <input x-model="productName" type="text" maxlength="160" placeholder="e.g. Northstar hydration"
                            class="mt-2 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    </label>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Campaign goal
                        <select x-model="goal" class="mt-2 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <option value="Awareness">Awareness</option>
                            <option value="Conversions">Conversions</option>
                            <option value="UGC production">UGC production</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Platform
                        <select x-model="platform" class="mt-2 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <option>Instagram Reels</option>
                            <option>TikTok</option>
                            <option>YouTube Shorts</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Working budget
                        <div class="relative mt-2">
                            <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-sm text-gray-400" x-text="currencySymbol"></span>
                            <input x-model.number="budget" type="number" min="0" max="999999999" step="500" class="h-11 w-full rounded-lg border border-gray-300 bg-transparent ps-8 pe-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        </div>
                    </label>
                </div>

                <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-gray-800 dark:text-white/90">Quick-start templates</p>
                        <span class="text-xs text-gray-400">Select one to draft</span>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-3">
                        <button type="button" @click="applyPreset('awareness')" class="rounded-lg border border-gray-200 p-3 text-start transition hover:border-brand-300 hover:bg-brand-25 dark:border-gray-700 dark:hover:border-brand-500/50 dark:hover:bg-white/[0.03]">
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-200">Brand lift</span><span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Reach & recall</span>
                        </button>
                        <button type="button" @click="applyPreset('conversion')" class="rounded-lg border border-gray-200 p-3 text-start transition hover:border-brand-300 hover:bg-brand-25 dark:border-gray-700 dark:hover:border-brand-500/50 dark:hover:bg-white/[0.03]">
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-200">Conversion push</span><span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Trackable action</span>
                        </button>
                        <button type="button" @click="applyPreset('ugc')" class="rounded-lg border border-gray-200 p-3 text-start transition hover:border-brand-300 hover:bg-brand-25 dark:border-gray-700 dark:hover:border-brand-500/50 dark:hover:bg-white/[0.03]">
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-200">UGC library</span><span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Reusable assets</span>
                        </button>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Contract clauses selected below are included in this draft.</p>
                    <button type="button" @click="generateBrief" class="inline-flex items-center gap-2 rounded-xl bg-brand-900 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-sm transition hover:-translate-y-0.5 hover:bg-brand-800 focus:outline-none focus:ring-4 focus:ring-brand-500/25 dark:bg-brand-700 dark:hover:bg-brand-600">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2.5 11.8 8l5.7 2-5.7 2L10 17.5 8.2 12 2.5 10l5.7-2L10 2.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                        Generate brief
                    </button>
                </div>

                <div x-show="generatedBrief" x-cloak x-transition class="mt-5 border-t border-gray-200 pt-5 dark:border-gray-800">
                    <label class="block text-sm font-semibold text-gray-800 dark:text-white/90" for="generated-brief">Draft brief <span class="font-normal text-gray-400">· editable</span></label>
                    <textarea id="generated-brief" x-model="generatedBrief" rows="12" maxlength="10000" class="mt-2 w-full rounded-xl border border-gray-300 bg-gray-50 p-4 font-mono text-xs leading-5 text-gray-700 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"></textarea>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <div class="flex items-start gap-3">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-300">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3 16.5h14M5 13V9m5 4V4m5 9V7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="m4.5 6 5-3 5 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <div><h2 class="text-lg font-semibold text-gray-900 dark:text-white">Rates & budget estimator</h2><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Model a tier mix and compare estimated delivery with your reach goal.</p></div>
                </div>

                <div class="mt-6 space-y-5">
                    <label class="block">
                        <span class="flex items-center justify-between gap-3 text-sm font-medium text-gray-700 dark:text-gray-300"><span>Total budget</span><strong class="text-brand-700 dark:text-brand-300" x-text="formatMoney(budget)"></strong></span>
                        <input x-model.number="budget" type="range" min="1000" max="250000" step="1000" class="mt-3 h-2 w-full cursor-pointer accent-brand-700">
                    </label>
                    <label class="block">
                        <span class="flex items-center justify-between gap-3 text-sm font-medium text-gray-700 dark:text-gray-300"><span>Target reach</span><strong class="text-brand-700 dark:text-brand-300" x-text="formatCompact(targetReach)"></strong></span>
                        <input x-model.number="targetReach" type="range" min="50000" max="3000000" step="25000" class="mt-3 h-2 w-full cursor-pointer accent-brand-700">
                    </label>
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                        <div class="flex items-center justify-between text-sm"><span class="font-semibold text-gray-800 dark:text-white/90">Creator tier mix</span><span class="text-xs text-gray-500">100% total</span></div>
                        <div class="mt-4 space-y-3">
                            <label class="grid grid-cols-[1fr_auto] items-center gap-x-3 text-xs text-gray-600 dark:text-gray-300"><span>Micro creators</span><strong x-text="`${microShare}%`"></strong><input class="col-span-2 mt-1 h-1.5 w-full cursor-pointer accent-brand-700" x-model.number="microShare" @input="normalizeMix('micro')" type="range" min="20" max="80" step="5"></label>
                            <label class="grid grid-cols-[1fr_auto] items-center gap-x-3 text-xs text-gray-600 dark:text-gray-300"><span>Mid-tier creators</span><strong x-text="`${midShare}%`"></strong><input class="col-span-2 mt-1 h-1.5 w-full accent-gray-400" type="range" :value="midShare" min="0" max="80" disabled aria-label="Mid-tier share adjusts automatically"></label>
                            <label class="grid grid-cols-[1fr_auto] items-center gap-x-3 text-xs text-gray-600 dark:text-gray-300"><span>Macro creators</span><strong x-text="`${macroShare}%`"></strong><input class="col-span-2 mt-1 h-1.5 w-full cursor-pointer accent-brand-700" x-model.number="macroShare" @input="normalizeMix('macro')" type="range" min="0" :max="100 - microShare" step="5"></label>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.04]"><p class="text-[11px] text-gray-500 dark:text-gray-400">Blended CPM</p><p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white" x-text="formatMoney(blendedCpm)"></p></div>
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.04]"><p class="text-[11px] text-gray-500 dark:text-gray-400">Impressions</p><p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white" x-text="formatCompact(estimatedImpressions)"></p></div>
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.04]"><p class="text-[11px] text-gray-500 dark:text-gray-400">Engagements</p><p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white" x-text="`${formatCompact(engagementLow)}–${formatCompact(engagementHigh)}`"></p></div>
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.04]"><p class="text-[11px] text-gray-500 dark:text-gray-400">Est. CPE</p><p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white" x-text="formatMoney(estimatedCpe)"></p></div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-brand-100 bg-brand-25 px-4 py-3 dark:border-brand-500/20 dark:bg-brand-500/10">
                        <span class="text-sm text-gray-600 dark:text-gray-300">Suggested rate per creator post/reel</span>
                        <strong class="text-base text-brand-800 dark:text-brand-200" x-text="formatMoney(suggestedRate)"></strong>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400"><span x-text="`${reachAttainment}% of the target reach`"></span> at the current budget and tier mix.</p>
                    <p class="text-xs leading-5 text-gray-400">Planning estimates only, using illustrative CPM and engagement assumptions. Actual rates and performance vary by creator and market.</p>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <div class="flex items-start gap-3">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 3.5h7l3 3v10H5v-13Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M12 3.8v3h3M7.5 10h5m-5 3h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    </span>
                    <div><h2 class="text-lg font-semibold text-gray-900 dark:text-white">Deliverables & contract clauses</h2><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Set expectations before a creator accepts the work.</p></div>
                </div>

                <div class="mt-5 space-y-4">
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                        <p class="text-sm font-semibold text-gray-800 dark:text-white/90">Content package builder</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Choose the standard deliverables to include in your brief.</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300"><input x-model="packageDeliverables" value="short_video" type="checkbox" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"><span>Short-form video</span></label>
                            <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300"><input x-model="packageDeliverables" value="story_frames" type="checkbox" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"><span>Story frames</span></label>
                            <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300"><input x-model="packageDeliverables" value="raw_footage" type="checkbox" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"><span>Raw footage</span></label>
                            <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300"><input x-model="packageDeliverables" value="still_images" type="checkbox" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"><span>Edited still images</span></label>
                            <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300"><input x-model="packageDeliverables" value="demo" type="checkbox" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"><span>Product walkthrough</span></label>
                        </div>
                    </div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Usage rights duration
                        <select x-model.number="usageMonths" class="mt-2 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <option :value="3">3 months</option><option :value="6">6 months</option><option :value="12">12 months</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category exclusivity
                        <select x-model.number="exclusivityDays" class="mt-2 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <option :value="0">No exclusivity</option><option :value="30">30 days</option><option :value="60">60 days</option><option :value="90">90 days</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Included revision rounds
                        <input x-model.number="revisionRounds" type="number" min="0" max="10" class="mt-2 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    </label>
                    <div class="space-y-3 rounded-xl bg-gray-50 p-4 dark:bg-white/[0.04]">
                        <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300"><input x-model="paidAmplification" type="checkbox" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"><span>Paid amplification / whitelisting permission</span></label>
                        <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300"><input x-model="repurposeContent" type="checkbox" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"><span>Permission to repurpose content on brand channels</span></label>
                    </div>
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Clause summary</p>
                        <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                            <li>Usage rights: <strong x-text="`${usageMonths} months`"></strong></li>
                            <li>Exclusivity: <strong x-text="exclusivityDays ? `${exclusivityDays} days` : 'None'"></strong></li>
                            <li>Revisions: <strong x-text="`${revisionRounds} rounds`"></strong></li>
                            <li>Paid usage: <strong x-text="paidAmplification ? 'Included' : 'Not included'"></strong></li>
                            <li>Content repurposing: <strong x-text="repurposeContent ? 'Included' : 'Not included'"></strong></li>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <div class="flex items-start gap-3">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-300">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8" cy="7" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M2.5 16c.4-2.5 2.2-4 5.5-4 3.2 0 5.1 1.5 5.5 4M14 5.5a2.5 2.5 0 0 1 0 5m1 2c1.5.5 2.3 1.5 2.5 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    </span>
                    <div><h2 class="text-lg font-semibold text-gray-900 dark:text-white">Audience & niche matcher</h2><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Find vetted profiles in your workspace roster that fit the target market and channel.</p></div>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Platform
                        <select x-model="matcherPlatform" class="mt-2 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <option value="any">Any platform</option>
                            @foreach ($platforms as $platformValue => $platformLabel)
                                <option value="{{ $platformValue }}">{{ $platformLabel }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Target market
                        <select x-model="matcherCountry" class="mt-2 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <option value="any">Any market</option>
                            @foreach ($countries as $country)
                                <option value="{{ $country }}">{{ $country }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Niche / audience focus
                        <input x-model="nicheFocus" type="text" placeholder="e.g. sustainable skincare" class="mt-2 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    </label>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Optimize for
                        <select x-model="matcherGoal" class="mt-2 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <option>Awareness</option><option>Conversions</option><option>UGC production</option>
                        </select>
                    </label>
                </div>
                <p class="mt-3 text-xs leading-5 text-gray-400">Niche is planning context. Matches are ranked from your vetted creator roster using platform, market, pulse score, audience size, and engagement; niche tags are not stored in creator profiles yet.</p>

                <div class="mt-4 space-y-2">
                    <template x-for="creator in creatorMatches" :key="creator.id">
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-800 dark:text-white/90" x-text="creator.name"></p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-text="`${creator.platformName} · ${creator.country || 'Market not set'} · ${creator.tier} tier`"></p>
                            </div>
                            <div class="shrink-0 text-end">
                                <p class="text-xs font-semibold text-brand-700 dark:text-brand-300" x-text="creatorFitLabel(creator)"></p>
                                <p class="mt-1 text-[11px] text-gray-400" x-text="`${formatCompact(creator.followers)} followers · ${Number(creator.engagement).toFixed(1)}% engagement`"></p>
                            </div>
                        </div>
                    </template>
                    <div x-show="creatorMatches.length === 0" class="rounded-xl border border-dashed border-gray-300 p-5 text-center dark:border-gray-700">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">No vetted profiles match these filters.</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Try another market or platform, or add and vet creators in your roster.</p>
                        <a href="{{ route('influencers') }}" class="mt-3 inline-flex text-sm font-medium text-brand-600 hover:underline dark:text-brand-300">Open creator roster</a>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <div class="flex items-start gap-3">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-300">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2.5 17 5v4.7c0 3.8-2.8 6.4-7 7.8-4.2-1.4-7-4-7-7.8V5l7-2.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="m6.8 10 2.1 2.1 4.4-4.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <div><h2 class="text-lg font-semibold text-gray-900 dark:text-white">Compliance & integrity pre-check</h2><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Catch common disclosure gaps before content goes live.</p></div>
                </div>

                <label for="caption-check" class="mt-5 block text-sm font-medium text-gray-700 dark:text-gray-300">Paste a caption or disclosure note</label>
                <textarea id="caption-check" x-model="captionText" rows="6" placeholder="Paste creator caption text here..." class="mt-2 w-full rounded-xl border border-gray-300 bg-transparent p-3 text-sm leading-6 text-gray-800 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></textarea>
                <div class="mt-3 flex items-start gap-3 rounded-xl p-3" :class="disclosurePresent ? 'bg-success-50 dark:bg-success-500/10' : 'bg-warning-50 dark:bg-warning-500/10'" role="status" aria-live="polite">
                    <span class="mt-0.5" :class="disclosurePresent ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400'">
                        <svg x-show="disclosurePresent" x-cloak class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m4.5 10.2 3.4 3.4 7.6-7.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <svg x-show="!disclosurePresent" x-cloak class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 6v4m0 3h.01M3.8 15.2 8.6 4.8a1.55 1.55 0 0 1 2.8 0l4.8 10.4a1.55 1.55 0 0 1-1.4 2.2H5.2a1.55 1.55 0 0 1-1.4-2.2Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <p class="text-sm" :class="disclosurePresent ? 'text-success-700 dark:text-success-300' : 'text-warning-700 dark:text-warning-300'" x-text="disclosurePresent ? 'Common sponsorship disclosure wording found.' : (captionText.trim() ? 'No common disclosure wording found. Add a clear #ad or paid partnership disclosure.' : 'Paste text to check for #ad, #sponsored, or paid partnership wording.')"></p>
                </div>
                <button type="button" @click="addDisclosure" class="mt-3 rounded-lg border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700 transition hover:border-brand-300 hover:text-brand-700 dark:border-gray-700 dark:text-gray-300 dark:hover:text-brand-300">Add #ad to caption</button>
                <div class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-800">
                    <p class="text-sm font-semibold text-gray-800 dark:text-white/90">Copyright & music</p>
                    <p class="mt-1 text-sm leading-6 text-gray-500 dark:text-gray-400">Use original audio or music licensed for the intended commercial use. Confirm paid usage, territory, duration, and platform coverage before amplifying creator content.</p>
                </div>
                <p class="mt-3 text-xs leading-5 text-gray-400">This quick text check does not determine legal compliance. Review current FTC/ASA guidance and the rules for each market and platform.</p>
            </section>
        </div>

        <section class="rounded-2xl border border-brand-100 bg-white p-5 shadow-theme-sm dark:border-brand-500/20 dark:bg-white/[0.03] md:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-600 dark:text-brand-300">Ready for the next step?</p>
                    <h2 class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">Put your draft to work.</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Apply the brief to an active campaign or carry it into a new campaign setup.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a :href="newCampaignUrl" class="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:border-brand-300 hover:bg-brand-25 hover:text-brand-800 focus:outline-none focus:ring-4 focus:ring-brand-500/20 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/[0.05]">
                        Launch new campaign
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-5-5 5 5-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                </div>
            </div>

            <div class="mt-5 grid gap-4 border-t border-gray-200 pt-5 dark:border-gray-800 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                <form method="POST" :action="applyUrl" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                    @csrf
                    <input type="hidden" name="campaign_id" :value="campaignId">
                    <input type="hidden" name="objective" :value="goal">
                    <input type="hidden" name="brief" :value="generatedBrief">
                    <input type="hidden" name="budget_total" :value="applyBudget ? budget : ''">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Apply to active campaign
                        <select x-model="campaignId" class="mt-2 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" @if($activeCampaigns->isEmpty()) disabled @endif>
                            <option value="">Select an active campaign</option>
                            @foreach ($activeCampaigns as $campaign)
                                <option value="{{ $campaign->id }}">{{ $campaign->name }} · {{ auth()->user()->tenant->currency }} {{ number_format((float) $campaign->budget_total, 0) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" :disabled="!campaignId || !generatedBrief.trim()" class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-900 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-sm transition hover:-translate-y-0.5 hover:bg-brand-800 focus:outline-none focus:ring-4 focus:ring-brand-500/25 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-brand-700 dark:hover:bg-brand-600">Apply to active campaign</button>
                    <label class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 sm:col-span-2"><input x-model="applyBudget" type="checkbox" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500/20">Also update the campaign budget to the estimate</label>
                </form>
                @if ($activeCampaigns->isEmpty())
                    <p class="text-xs text-gray-500 dark:text-gray-400">No active campaigns yet. Launch a new campaign to use this draft.</p>
                @endif
            </div>
        </section>
    </div>
@endsection
