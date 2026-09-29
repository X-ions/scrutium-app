@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Connected accounts</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Tokens are stored encrypted and are never sent to the browser.
            </p>

            @if ($accounts->isEmpty())
                <div class="mt-4">
                    <x-socialhub.empty-state
                        title="No accounts connected yet"
                        description="Connect a network below to start publishing. You can connect several and publish the same post to all of them."
                        icon="user-profile" />
                </div>
            @else
                <div class="mt-4 grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                    @foreach ($accounts as $account)
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ $account['provider_display_name'] ?: $account['provider_username'] ?: $account['provider_label'] }}
                                    </p>
                                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                        {{ $account['provider_username'] ? '@'.$account['provider_username'] : $account['provider_account_id'] }}
                                    </p>
                                </div>
                                <x-socialhub.platform-chip :provider="$account['provider']" />
                            </div>

                            <dl class="mt-3 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                                <div class="flex justify-between gap-2">
                                    <dt>Status</dt>
                                    <dd @class([
                                        'font-medium',
                                        'text-success-600 dark:text-success-500' => $account['status'] === 'connected',
                                        'text-warning-600 dark:text-warning-500' => in_array($account['status'], ['expired', 'error'], true),
                                        'text-error-600 dark:text-error-500' => $account['status'] === 'revoked',
                                    ])>{{ $account['status_label'] }}</dd>
                                </div>
                                @if ($account['token_expires_at'])
                                    <div class="flex justify-between gap-2">
                                        <dt>Token expires</dt>
                                        <dd>{{ \Illuminate\Support\Carbon::parse($account['token_expires_at'])->format('j M Y') }}</dd>
                                    </div>
                                @endif
                                @if ($account['last_synced_at'])
                                    <div class="flex justify-between gap-2">
                                        <dt>Last sync</dt>
                                        <dd>{{ \Illuminate\Support\Carbon::parse($account['last_synced_at'])->diffForHumans() }}</dd>
                                    </div>
                                @endif
                            </dl>

                            @if ($account['last_error'])
                                <p class="mt-2 rounded-lg bg-warning-50 p-2 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                                    {{ $account['last_error'] }}
                                </p>
                            @endif

                            <div class="mt-3 flex flex-wrap gap-2">
                                @if (in_array($account['status'], ['connected', 'expired'], true))
                                    <form method="POST" action="{{ route('socialhub.accounts.refresh', $account['id']) }}">
                                        @csrf
                                        <button class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Refresh</button>
                                    </form>
                                @endif

                                @if (in_array($account['status'], ['expired', 'revoked'], true))
                                    <a href="{{ route('socialhub.accounts.connect', $account['provider']) }}"
                                        class="rounded-lg bg-brand-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-brand-700">Reconnect</a>
                                @endif

                                <form method="POST" action="{{ route('socialhub.accounts.sync', $account['id']) }}">
                                    @csrf
                                    <button class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Sync</button>
                                </form>

                                <form method="POST" action="{{ route('socialhub.accounts.disconnect', $account['id']) }}"
                                    onsubmit="return confirm('Disconnect this account? Stored credentials will be deleted.')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded-lg border border-error-200 px-2.5 py-1.5 text-xs font-medium text-error-600 hover:bg-error-50 dark:border-error-500/30 dark:text-error-400">Disconnect</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Connect a network</h2>

            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($providers as $descriptor)
                    <div @class([
                        'flex flex-col justify-between rounded-xl border p-4',
                        'border-gray-200 dark:border-gray-700' => $descriptor->isUsable(),
                        'border-dashed border-gray-300 opacity-70 dark:border-gray-700' => ! $descriptor->isUsable(),
                    ])>
                        <div>
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $descriptor->name }}</h3>
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                    'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' => $descriptor->isUsable(),
                                    'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => ! $descriptor->isUsable(),
                                ])>{{ $descriptor->isUsable() ? 'Ready' : 'Not configured' }}</span>
                            </div>

                            @if (! $descriptor->isUsable() && $descriptor->notConfiguredReason)
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $descriptor->notConfiguredReason }}</p>
                            @endif

                            @if ($descriptor->requiresAppReview ?? false)
                                <p class="mt-2 text-xs text-warning-700 dark:text-warning-400">
                                    Requires Meta/Google app review before third-party publishing is enabled.
                                </p>
                            @endif

                            <ul class="mt-2 flex flex-wrap gap-1">
                                @foreach (['imagePublishing' => 'Images', 'videoPublishing' => 'Video', 'carouselPublishing' => 'Carousel', 'stories' => 'Stories', 'scheduling' => 'Scheduling', 'comments' => 'Comments', 'commentReplies' => 'Replies', 'analytics' => 'Analytics', 'followers' => 'Followers'] as $flag => $caption)
                                    <li @class([
                                        'rounded px-1.5 py-0.5 text-[10px] font-medium',
                                        'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' => $descriptor->capabilities->toArray()[$flag] ?? false,
                                        'bg-gray-100 text-gray-400 line-through dark:bg-white/5 dark:text-gray-600' => ! ($descriptor->capabilities->toArray()[$flag] ?? false),
                                    ])>{{ $caption }}</li>
                                @endforeach
                            </ul>
                        </div>

                        @if ($descriptor->isUsable())
                            <a href="{{ route('socialhub.accounts.connect', $descriptor->key) }}"
                                class="mt-3 inline-block rounded-lg bg-brand-600 px-3 py-2 text-center text-sm font-medium text-white hover:bg-brand-700">
                                Connect {{ $descriptor->name }}
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection
