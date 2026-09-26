@extends('layouts.app')

@section('content')
    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-gradient-to-r from-[#0B1B33] to-[#163056] p-6 text-white dark:border-gray-800 md:p-8">
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-300">Executive overview</p>
                <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Campaign performance center</h1>
                <p class="mt-2 max-w-xl text-sm text-white/70">Real-time monitoring of campaign lifecycles, target attainment, and workspace health.</p>
            </div>
            <a href="{{ route('campaigns') }}" class="inline-flex items-center justify-center rounded-lg bg-amber-500 px-4 py-2.5 text-sm font-semibold text-[#0B1B33] hover:bg-amber-400">Review targets</a>
        </div>
    </div>

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <div class="mb-5 flex items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Data lifecycle status</h2>
                <p class="text-sm text-gray-500">End-to-end tracking from briefing to reconciliation</p>
            </div>
            <span class="rounded-full bg-success-50 px-3 py-1 text-xs font-medium text-success-700 dark:bg-success-500/15 dark:text-success-400">Live sync</span>
        </div>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($pipeline as $item)
                <div class="rounded-xl border border-gray-100 p-4 text-center dark:border-gray-800">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $item['stage']->label() }}</p>
                    <p class="mt-2 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $item['count'] }}</p>
                    <p class="mt-1 text-xs text-gray-400">campaigns</p>
                </div>
            @endforeach
        </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($metrics as $metric)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $metric['label'] }}</p>
                <p class="mt-2 text-3xl font-bold text-gray-800 dark:text-white/90">{{ number_format((float) $metric['value']) }}{{ $metric['suffix'] ?? '' }}</p>
                <p class="mt-2 text-xs text-gray-400">{{ $metric['detail'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-2">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Attainment performance</h2>
            <p class="text-sm text-gray-500">Submitted vs approved deliverable completion</p>
            <div class="mt-6 space-y-5">
                <div>
                    <div class="mb-2 flex justify-between text-sm">
                        <span class="text-gray-500">Submitted or approved</span>
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $attainment['posted'] }}%</span>
                    </div>
                    <div class="h-2.5 rounded-full bg-gray-100 dark:bg-gray-800">
                        <div class="h-full rounded-full bg-blue-600" style="width: {{ min(100, (int) $attainment['posted']) }}%"></div>
                    </div>
                </div>
                <div>
                    <div class="mb-2 flex justify-between text-sm">
                        <span class="text-gray-500">Approved</span>
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $attainment['approved'] }}%</span>
                    </div>
                    <div class="h-2.5 rounded-full bg-gray-100 dark:bg-gray-800">
                        <div class="h-full rounded-full bg-[#0B1B33]" style="width: {{ min(100, (int) $attainment['approved']) }}%"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">System integrity</h2>
            <p class="text-sm text-gray-500">Live health of data engines</p>
            <div class="mt-5 space-y-4">
                <div>
                    <div class="mb-1 flex justify-between text-sm"><span class="text-gray-500">Open alerts</span><span>{{ $openAlerts->count() }}</span></div>
                    <div class="h-1.5 rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-full rounded-full bg-[#0B1B33]" style="width: {{ $openAlerts->count() ? '40%' : '100%' }}"></div></div>
                </div>
                <div>
                    <div class="mb-1 flex justify-between text-sm"><span class="text-gray-500">API sync</span><span>{{ $connectedIntegrations }}/{{ $integrations->count() }}</span></div>
                    <div class="h-1.5 rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-full rounded-full bg-[#0B1B33]" style="width: {{ $integrations->count() ? round(($connectedIntegrations / max($integrations->count(), 1)) * 100) : 0 }}%"></div></div>
                </div>
                <a href="{{ route('alerts') }}" class="mt-2 inline-flex w-full items-center justify-center rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300">Diagnostic report</a>
            </div>
        </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-2">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Recent campaigns</h2>
                <a href="{{ route('campaigns.create') }}" class="text-sm font-medium text-brand-600">New campaign</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-start text-xs uppercase tracking-wide text-gray-400">
                        <tr>
                            <th class="pb-3 font-medium">Campaign</th>
                            <th class="pb-3 font-medium">Stage</th>
                            <th class="pb-3 font-medium">Creators</th>
                            <th class="pb-3 font-medium">Budget</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($campaigns as $campaign)
                            <tr>
                                <td class="py-3"><a href="{{ route('campaigns.show', $campaign) }}" class="font-medium text-gray-800 hover:text-brand-600 dark:text-white/90">{{ $campaign->name }}</a></td>
                                <td class="py-3"><span class="rounded-full px-2 py-0.5 text-xs {{ $campaign->stage->badgeColor() }}">{{ $campaign->stage->label() }}</span></td>
                                <td class="py-3 text-gray-500">{{ $campaign->influencers_count }}</td>
                                <td class="py-3 text-gray-500">{{ number_format($campaign->budgetUtilisation(), 1) }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-10 text-center text-gray-500">No campaigns yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Efficiency leaders</h2>
                <p class="text-sm text-gray-500">Top creators by pulse score</p>
            </div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($topCreators as $creator)
                    <li class="flex items-center justify-between py-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-gray-800 dark:text-white/90">{{ $creator->displayName() }}</p>
                            <p class="text-xs text-gray-400">{{ $creator->formattedFollowers() }} followers</p>
                        </div>
                        <span class="text-sm font-semibold text-success-600">{{ number_format((float) $creator->pulse_score, 1) }}</span>
                    </li>
                @empty
                    <li class="py-8 text-center text-sm text-gray-500">No creators scored yet.</li>
                @endforelse
            </ul>
        </div>
    </div>
@endsection
