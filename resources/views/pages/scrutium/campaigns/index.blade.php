@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Campaigns" />
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div><h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Campaign central</h2><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Plan, staff, and monitor every campaign in your workspace.</p></div>
        <a href="{{ route('campaigns.create') }}" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">New campaign</a>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <form method="GET" class="mb-6 grid gap-3 md:grid-cols-4">
            <input name="search" value="{{ request('search') }}" placeholder="Search campaigns" class="h-11 rounded-lg border border-gray-300 bg-transparent px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <select name="status" class="h-11 rounded-lg border border-gray-300 bg-transparent px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"><option value="">All statuses</option>@foreach($statuses as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select>
            <select name="stage" class="h-11 rounded-lg border border-gray-300 bg-transparent px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"><option value="">All stages</option>@foreach($stages as $key => $label)<option value="{{ $key }}" @selected(request('stage') === $key)>{{ $label }}</option>@endforeach</select>
            <button class="rounded-lg bg-gray-100 px-4 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-white" type="submit">Filter</button>
        </form>
        <div class="overflow-x-auto"><table class="min-w-full text-start text-sm"><thead class="bg-gray-50 text-gray-500 dark:bg-white/[0.03]"><tr><th class="px-4 py-3 text-start">Campaign</th><th class="px-4 py-3 text-start">Status</th><th class="px-4 py-3 text-start">Stage</th><th class="px-4 py-3 text-start">Roster</th><th class="px-4 py-3 text-start">Budget</th><th class="px-4 py-3 text-start"></th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
        @forelse($campaigns as $campaign)<tr><td class="px-4 py-4"><a href="{{ route('campaigns.show', $campaign) }}" class="font-semibold text-gray-800 hover:text-brand-600 dark:text-white/90">{{ $campaign->name }}</a><p class="mt-1 text-xs text-gray-400">{{ $campaign->objective ?: 'No objective set' }}</p></td><td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $campaign->status->badgeColor() }}">{{ $campaign->status->label() }}</span></td><td class="px-4 py-4 text-gray-500">{{ $campaign->stage->label() }}</td><td class="px-4 py-4 text-gray-500">{{ $campaign->influencers_count }} creators</td><td class="px-4 py-4 text-gray-500">{{ $campaign->currency }} {{ number_format((float) $campaign->budget_total, 0) }}</td><td class="px-4 py-4 text-end"><a href="{{ route('campaigns.show', $campaign) }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Open</a></td></tr>
        @empty<tr><td colspan="6" class="px-4 py-12 text-center text-gray-500">No campaigns match this view.</td></tr>@endforelse
        </tbody></table></div><div class="mt-6">{{ $campaigns->links() }}</div>
    </div>
@endsection