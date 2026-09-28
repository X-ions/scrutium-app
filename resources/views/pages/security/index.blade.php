@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb page-title="Security" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-title-md font-bold text-gray-900 dark:text-white">Security activity</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Every sign-in and account change we recorded, newest first.</p>
                    </div>
                    <a href="{{ route('security.sessions') }}"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                        Active sessions
                    </a>
                </div>

                @if ($events->isEmpty())
                    <p class="mt-8 rounded-lg bg-gray-50 p-6 text-center text-sm text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                        No security events recorded yet.
                    </p>
                @else
                    <ul class="mt-6 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($events as $event)
                            <li class="flex flex-wrap items-start justify-between gap-3 py-4">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ $event->getEventTypeLabel() }}
                                        @if ($event->risk_score !== 'low')
                                            <span @class([
                                                'ml-2 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                                'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400' => $event->risk_score === 'critical',
                                                'bg-orange-50 text-orange-700 dark:bg-orange-900/20 dark:text-orange-400' => $event->risk_score === 'high',
                                                'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400' => $event->risk_score === 'medium',
                                            ])>
                                                {{ $event->getRiskScoreLabel() }} risk
                                            </span>
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $event->occurred_at->format('j M Y, H:i') }} T
                                        @if ($event->ip_address) &middot; {{ $event->ip_address }} @endif
                                        @if ($event->location_city) &middot; {{ $event->location_city }} @endif
                                    </p>
                                    <p class="mt-1 font-mono text-[11px] text-gray-400 dark:text-gray-500">Event #{{ $event->id }}</p>
                                </div>

                                <div class="flex items-center gap-2">
                                    @if ($event->acknowledged_at)
                                        <span class="rounded-lg bg-green-50 px-3 py-1.5 text-xs font-medium text-green-700 dark:bg-green-900/20 dark:text-green-400">
                                            Reviewed
                                        </span>
                                    @else
                                        <form method="POST" action="{{ route('security.events.acknowledge', $event) }}">
                                            @csrf
                                            <button type="submit"
                                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                                                Mark reviewed
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-4">{{ $events->links() }}</div>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <h2 class="text-title-sm font-bold text-gray-900 dark:text-white">At a glance</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Trusted devices</dt>
                        <dd class="font-semibold text-gray-900 dark:text-white">{{ $stats['trusted_devices'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Awaiting confirmation</dt>
                        <dd class="font-semibold text-gray-900 dark:text-white">{{ $stats['untrusted_devices'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Active sessions</dt>
                        <dd class="font-semibold text-gray-900 dark:text-white">{{ $stats['active_sessions'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Suspicious events (30d)</dt>
                        <dd class="font-semibold text-gray-900 dark:text-white">{{ $stats['suspicious'] }}</dd>
                    </div>
                </dl>
            </div>

            @if ($pendingDevices->isNotEmpty())
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-800 dark:bg-amber-900/20">
                    <h2 class="text-title-sm font-bold text-amber-900 dark:text-amber-300">Waiting for your confirmation</h2>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-300/90">
                        Confirm these devices so we stop emailing you about them.
                    </p>
                    <ul class="mt-4 space-y-3">
                        @foreach ($pendingDevices as $device)
                            <li class="rounded-lg bg-white p-3 dark:bg-white/[0.03]">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $device->getDisplayName() }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $device->trusted_location_city ?? 'Unknown location' }} &middot; {{ $device->trusted_ip }}
                                </p>
                                <form method="POST" action="{{ route('security.devices.confirm', $device->confirmation_token) }}"
                                    class="mt-3">
                                    @csrf
                                    <button type="submit"
                                        class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700">
                                        This was me
                                    </button>
                                </form>                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <h2 class="text-title-sm font-bold text-gray-900 dark:text-white">Email preferences</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    We only email when something needs your attention. Routine sign-ins from devices you have confirmed are never emailed.
                </p>

                <form method="POST" action="{{ route('security.notifications.update') }}" class="mt-5 space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <label for="sensitivity" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Sensitivity</label>
                        <select id="sensitivity" name="sensitivity"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                            @foreach (\App\Models\SecurityNotificationPreference::SENSITIVITY_LEVELS as $value => $label)
                                <option value="{{ $value }}" @selected($preferences->sensitivity === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <fieldset>
                        <legend class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Notify me about</legend>
                        <div class="space-y-2">
                            @php
                                $toggles = [
                                    'notify_new_device' => 'Sign-ins from new devices',
                                    'notify_new_browser' => 'Sign-ins from new browsers',
                                    'notify_new_os' => 'Sign-ins from new operating systems',
                                    'notify_new_location' => 'Sign-ins from new locations',
                                    'notify_impossible_travel' => 'Impossible travel',
                                    'notify_high_risk_location' => 'High-risk locations',
                                    'notify_suspicious_activity' => 'Suspicious activity',
                                    'notify_failed_attempts' => 'Repeated failed sign-ins',
                                    'notify_password_change' => 'Password changes',
                                    'notify_email_change' => 'Email changes',
                                    'notify_mfa_change' => 'Two-factor authentication changes',
                                    'notify_recovery_change' => 'Recovery method changes',
                                    'notify_api_key_change' => 'API key changes',
                                    'notify_ownership_transfer' => 'Workspace ownership transfers',
                                    'notify_account_locked' => 'Account lockouts',
                                ];
                            @endphp
                            @foreach ($toggles as $field => $label)
                                <label class="flex items-start gap-2.5 text-sm text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" name="events[]" value="{{ $field }}"
                                        @checked($preferences->{$field})
                                        class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-white/[0.03]">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="max_emails_per_hour" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Max emails per hour</label>
                            <input type="number" id="max_emails_per_hour" name="max_emails_per_hour" min="1" max="10"
                                value="{{ $preferences->max_emails_per_hour }}"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                        </div>
                        <div>
                            <label for="max_emails_per_day" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Max emails per day</label>
                            <input type="number" id="max_emails_per_day" name="max_emails_per_day" min="1" max="50"
                                value="{{ $preferences->max_emails_per_day }}"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                        </div>
                    </div>

                    <label class="flex items-center gap-2.5 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="email_enabled" value="1" @checked($preferences->email_enabled)
                            class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-white/[0.03]">
                        <span>Send security emails</span>
                    </label>

                    <button type="submit" class="btn-primary w-full rounded-xl py-2.5 text-sm font-semibold">
                        Save preferences
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
