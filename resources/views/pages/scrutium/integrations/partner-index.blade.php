@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="Partner integrations" />

<div x-data="{
    category: 'all',
    connectionOpen: false,
    selectedProvider: '',
    selectedName: '',
    connectionName: '',
    connectionScope: 'workspace',
    connectionCampaignId: '',
    hasSavedToken: false,
    isReconnectMode: false,
    openConnection(provider, name, scope, campaignId, hasToken) {
        this.selectedProvider = provider;
        this.selectedName = name;
        this.connectionName = name;
        this.connectionScope = scope || 'workspace';
        this.connectionCampaignId = campaignId || '';
        this.hasSavedToken = hasToken;
        this.isReconnectMode = !hasToken;
        this.connectionOpen = true;
    },
    closeConnection() {
        this.connectionOpen = false;
    }
}" @keydown.escape.window="closeConnection()">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-blue-light-700 dark:text-blue-light-300">{{ __('Partner integrations') }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ __('Integration Hub') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ __('Bring creator insights, campaign sales, and team workflows into one workspace.') }}</p>
        </div>
        <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <span class="grid size-9 place-items-center rounded-md bg-blue-light-50 text-sm font-semibold text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-300">
                {{ $connectedCount }}
            </span>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('API sync') }}</p>
                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $connectedCount }} / {{ count($providers) }} {{ __('connected') }}</p>
            </div>
        </div>
    </header>

    <div class="mb-5 flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('Filter integrations') }}">
        <button type="button" @click="category = 'all'" :aria-pressed="category === 'all'"
            :class="category === 'all' ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-white/5'"
            class="rounded-md px-3 py-2 text-sm font-medium">{{ __('All') }}</button>
        @foreach ($categories as $key => $label)
            <button type="button" @click="category = @js($key)" :aria-pressed="category === @js($key)"
                :class="category === @js($key) ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-white/5'"
                class="rounded-md px-3 py-2 text-sm font-medium">{{ __($label) }}</button>
        @endforeach
    </div>

    <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
        @foreach ($providers as $provider)
            @php($connection = $provider['integration'])
            <article x-show="category === 'all' || category === @js($provider['category'])"
                class="flex min-h-48 flex-col rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-md bg-blue-light-50 text-xs font-bold text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-300">{{ $provider['badge'] }}</span>
                        <div class="min-w-0">
                            <h2 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ __($provider['name']) }}</h2>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __($categories[$provider['category']]) }}</p>
                        </div>
                    </div>
                    @if ($provider['status'] === 'Connected')
                        <span class="shrink-0 rounded-full bg-success-50 px-2 py-1 text-xs font-medium text-success-700 dark:bg-success-500/15 dark:text-success-300">{{ __('Connected') }}</span>
                    @elseif ($provider['status'] === 'Reconnect required')
                        <span class="shrink-0 rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700 dark:bg-warning-500/15 dark:text-warning-300">{{ __('Reconnect required') }}</span>
                    @else
                        <span class="shrink-0 rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('Available') }}</span>
                    @endif
                </div>

                <p class="mt-3 flex-1 text-sm leading-5 text-gray-600 dark:text-gray-300">{{ __($provider['description']) }}</p>

                @if ($connection)
                    <div class="mt-3 flex items-center justify-between gap-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                        <div class="min-w-0 text-xs text-gray-500 dark:text-gray-400">
                            <p class="truncate font-medium text-gray-700 dark:text-gray-200">{{ $connection->name }}</p>
                            <p>{{ $connection->scope === 'campaign' && $connection->campaign ? $connection->campaign->name : __('Workspace-wide') }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($connection->needsReconnect())
                                <button type="button" @click="openConnection(@js($provider['provider']), @js($provider['name']), @js($connection->scope), @js($connection->campaign_id), @js(! empty($connection->credentials['access_token'] ?? null)))"
                                    class="rounded-md bg-warning-600 px-3 py-2 text-xs font-semibold text-white hover:bg-warning-700 dark:bg-warning-500 dark:hover:bg-warning-400">{{ __('Reconnect') }}</button>
                            @else
                                <button type="button" @click="openConnection(@js($provider['provider']), @js($provider['name']), @js($connection->scope), @js($connection->campaign_id), @js(! empty($connection->credentials['access_token'] ?? null)))"
                                    class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">{{ __('Configure') }}</button>
                            @endif
                            @if ($provider['status'] === 'Connected')
                                <form method="POST" action="{{ route('integrations.disconnect', $connection) }}">
                                    @csrf
                                    <button class="rounded-md px-2 py-2 text-xs font-medium text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5">{{ __('Disconnect') }}</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                        <button type="button" @click="openConnection(@js($provider['provider']), @js($provider['name']), 'workspace', '', false)"
                            class="rounded-md bg-brand-600 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-700 dark:bg-brand-500 dark:hover:bg-brand-400">{{ __('Connect') }}</button>
                    </div>
                @endif
            </article>
        @endforeach
    </div>

    <div x-cloak x-show="connectionOpen" x-transition.opacity class="fixed inset-0 z-999999 flex items-center justify-center bg-gray-950/30 p-4" @click.self="closeConnection()">
        <section x-show="connectionOpen" x-transition.scale.origin.top class="w-full max-w-md rounded-lg border border-gray-200 bg-white shadow-theme-xl dark:border-gray-700 dark:bg-gray-900" role="dialog" aria-modal="true" aria-labelledby="connect-title">
            <header class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-blue-light-700 dark:text-blue-light-300">{{ __('Connect account') }}</p>
                    <h2 id="connect-title" class="mt-1 text-lg font-semibold text-gray-900 dark:text-white" x-text="selectedName"></h2>
                </div>
                <button type="button" @click="closeConnection()" class="grid size-8 place-items-center rounded-md text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10" aria-label="{{ __('Close connection setup') }}">
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
                </button>
            </header>

            <div class="space-y-4 px-5 py-4">
                <div class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800/70 dark:text-gray-300">
                    {{ __('You will be redirected to the provider to approve access. We never ask for raw API keys in this flow.') }}
                </div>

                <div class="flex justify-end gap-2 border-t border-gray-200 pt-3 dark:border-gray-800">
                    <button type="button" @click="closeConnection()" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-200">{{ __('Cancel') }}</button>
                    <a
                        x-bind:href="selectedProvider ? '/integrations/' + selectedProvider + '/oauth' : '#'
                        "
                        class="inline-flex items-center justify-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 dark:bg-brand-500 dark:hover:bg-brand-400"
                        x-text="isReconnectMode ? 'Reconnect with provider' : 'Connect with provider'"
                    >
                        {{ __('Connect with provider') }}
                    </a>
                </div>
            </div>
        </section>
    </div>
</div>
@endsection
