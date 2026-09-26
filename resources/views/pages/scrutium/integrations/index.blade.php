@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="Integrations" />

<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Integrations</h2>
        <p class="mt-1 text-sm text-gray-500">Connect provider accounts and verify that their APIs accept your credentials.</p>
    </div>
    <span class="text-xs text-gray-400">Credentials are encrypted at rest</span>
</div>

@if (session('success'))
    <div role="status" class="mb-5 rounded-lg border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-700 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-400">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div role="alert" class="mb-5 rounded-lg border border-error-200 bg-error-50 px-4 py-3 text-sm text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-400">{{ session('error') }}</div>
@endif

<div class="mb-6 grid gap-3 sm:grid-cols-3">
    <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="text-xs text-gray-500">Connected and verified</p>
        <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $connectedCount }}</p>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="text-xs text-gray-500">Needs attention</p>
        <p class="mt-1 text-2xl font-semibold text-warning-600 dark:text-warning-400">{{ $degradedCount }}</p>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="text-xs text-gray-500">Not connected</p>
        <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $disconnectedCount }}</p>
    </div>
</div>

<section class="mb-8 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="add-integration-title">
    <div class="mb-4">
        <h3 id="add-integration-title" class="text-base font-semibold text-gray-800 dark:text-white/90">Add a provider connection</h3>
            <p class="mt-1 text-sm text-gray-500">Use a provider-issued access token with permission to read the account profile. Saving runs a live account check.</p>
    </div>

    @if ($integrations->count() < count($providers))
        <form method="POST" action="{{ route('integrations.store') }}" class="grid gap-4 md:grid-cols-2" x-data="{ provider: '{{ old('provider', 'instagram') }}' }">
            @csrf
            <div>
                <label for="new-provider" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Provider</label>
                <select id="new-provider" name="provider" x-model="provider" required class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                    @foreach ($providers as $key => $label)
                        <option value="{{ $key }}" @selected(old('provider', 'instagram') === $key) @disabled($integrations->contains('provider', $key))>{{ $label }}{{ $integrations->contains('provider', $key) ? ' (already added)' : '' }}</option>
                    @endforeach
                </select>
                @error('provider')<p class="mt-1 text-sm text-error-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="new-name" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Connection name</label>
                <input id="new-name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="off" placeholder="e.g. Scrutium Instagram" class="h-10 w-full rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                @error('name')<p class="mt-1 text-sm text-error-600">{{ $message }}</p>@enderror
            </div>
            <div x-show="provider === 'shopify'" x-cloak>
                <label for="new-shop-domain" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Shopify store domain</label>
                <input id="new-shop-domain" name="shop_domain" value="{{ old('shop_domain') }}" placeholder="store-name.myshopify.com" autocomplete="url" class="h-10 w-full rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                @error('shop_domain')<p class="mt-1 text-sm text-error-600">{{ $message }}</p>@enderror
            </div>
            <div class="md:col-span-2">
                <label for="new-access-token" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Access token</label>
                <input id="new-access-token" name="access_token" type="password" required maxlength="8192" autocomplete="new-password" spellcheck="false" placeholder="Paste a provider-issued token" class="h-10 w-full rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                @error('access_token')<p class="mt-1 text-sm text-error-600">{{ $message }}</p>@enderror
                <p class="mt-1.5 text-xs text-gray-500">Read scopes: Instagram <code>instagram_business_basic</code>, TikTok <code>user.info.basic</code>, YouTube <code>youtube.readonly</code>, X <code>users.read</code>, LinkedIn <code>openid profile</code>, Shopify <code>read_shop</code>, GA4 <code>analytics.readonly</code>. Tokens are never shown again after saving.</p>
            </div>
            <div class="flex items-center justify-end">
                <button type="submit" class="inline-flex h-10 items-center justify-center rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">Save and verify</button>
            </div>
        </form>
    @else
        <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:bg-white/5">All supported providers have already been added to this workspace.</p>
    @endif
</section>

<section aria-labelledby="connections-title" x-data="{ filter: 'all' }">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 id="connections-title" class="text-base font-semibold text-gray-800 dark:text-white/90">Workspace connections</h3>
            <p class="mt-1 text-sm text-gray-500">A verified status means the provider accepted the credentials during the latest check.</p>
        </div>
        <div class="flex gap-1 rounded-lg border border-gray-200 p-1 dark:border-gray-800" role="group" aria-label="Filter integrations by status">
            <button type="button" @click="filter = 'all'" :aria-pressed="filter === 'all'" class="rounded-md px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 aria-pressed:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10 dark:aria-pressed:bg-white/10">All</button>
            <button type="button" @click="filter = 'connected'" :aria-pressed="filter === 'connected'" class="rounded-md px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 aria-pressed:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10 dark:aria-pressed:bg-white/10">Verified</button>
            <button type="button" @click="filter = 'degraded'" :aria-pressed="filter === 'degraded'" class="rounded-md px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 aria-pressed:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10 dark:aria-pressed:bg-white/10">Attention</button>
            <button type="button" @click="filter = 'disconnected'" :aria-pressed="filter === 'disconnected'" class="rounded-md px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 aria-pressed:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10 dark:aria-pressed:bg-white/10">Disconnected</button>
        </div>
    </div>

    <div class="space-y-4">
        @forelse ($integrations as $integration)
            <article x-show="filter === 'all' || filter === '{{ $integration->statusEnum()->value }}'" class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-brand-50 text-xs font-bold uppercase text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ strtoupper(substr($integration->provider, 0, 2)) }}</span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="font-semibold text-gray-800 dark:text-white/90">{{ $integration->name ?: ($providers[$integration->provider] ?? ucfirst($integration->provider)) }}</h4>
                                <span class="rounded-full px-2.5 py-1 text-xs {{ $integration->statusEnum()->badgeColor() }}">{{ $integration->statusEnum()->label() }}</span>
                            </div>
                            <p class="mt-1 text-sm text-gray-500">{{ $providers[$integration->provider] ?? ucfirst($integration->provider) }} · {{ $integration->last_checked_at ? 'Checked '.$integration->last_checked_at->diffForHumans() : 'Not checked yet' }}</p>
                            @if ($integration->last_error)
                                <p class="mt-2 text-sm text-error-600 dark:text-error-400">{{ $integration->last_error }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('integrations.connect', $integration) }}">
                            @csrf
                            <button type="submit" class="inline-flex h-9 items-center rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">Test connection</button>
                        </form>
                        @if ($integration->statusEnum() !== \App\Enums\IntegrationStatus::Disconnected)
                            <form method="POST" action="{{ route('integrations.disconnect', $integration) }}" onsubmit="return confirm('Disconnect this provider and remove its saved credentials?')">
                                @csrf
                                <button type="submit" class="inline-flex h-9 items-center rounded-lg px-3 text-sm font-medium text-error-600 hover:bg-error-50 dark:text-error-400 dark:hover:bg-error-500/10">Disconnect</button>
                            </form>
                        @endif
                    </div>
                </div>

                <details class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <summary class="cursor-pointer text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Edit connection credentials</summary>
                    <form method="POST" action="{{ route('integrations.credentials', $integration) }}" class="mt-4 grid gap-4 md:grid-cols-2" x-data="{ provider: '{{ $integration->provider }}' }">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label for="name-{{ $integration->id }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Connection name</label>
                            <input id="name-{{ $integration->id }}" name="name" value="{{ $integration->name }}" required maxlength="120" class="h-10 w-full rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                        </div>
                        @if ($integration->provider === 'shopify')
                            <div>
                                <label for="shop-{{ $integration->id }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Shopify store domain</label>
                                <input id="shop-{{ $integration->id }}" name="shop_domain" value="{{ $integration->credentials['shop_domain'] ?? '' }}" required placeholder="store-name.myshopify.com" class="h-10 w-full rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                            </div>
                        @endif
                        <div class="{{ $integration->provider === 'shopify' ? 'md:col-span-2' : '' }}">
                            <label for="token-{{ $integration->id }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Replace access token</label>
                            <input id="token-{{ $integration->id }}" name="access_token" type="password" maxlength="8192" autocomplete="new-password" spellcheck="false" placeholder="Leave blank to keep the saved token" class="h-10 w-full rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                            <p class="mt-1 text-xs text-gray-500">Saved token: {{ isset($integration->credentials['access_token']) ? '••••••••' : 'none' }}</p>
                        </div>
                        <div class="flex items-center justify-end">
                            <button type="submit" class="inline-flex h-10 items-center justify-center rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">Save and verify</button>
                        </div>
                    </form>
                </details>
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 px-6 py-12 text-center dark:border-gray-700">
                <h4 class="font-medium text-gray-800 dark:text-white/90">No provider connections yet</h4>
                <p class="mt-1 text-sm text-gray-500">Add a provider token above to validate the first connection for this workspace.</p>
            </div>
        @endforelse
    </div>
</section>
@endsection