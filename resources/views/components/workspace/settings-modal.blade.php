@props(['workspace'])

@php
    use App\Http\Controllers\LocaleController;
    use Illuminate\Support\Facades\Storage;

    $supportedLocales = LocaleController::SUPPORTED_LOCALES;
    $nameAvailableAt = $workspace->name_updated_at?->copy()->addDays(7);
    $canRenameWorkspace = ! $nameAvailableAt || ! $nameAvailableAt->isFuture();
    $securityAlertsSubscribed = auth()->user()->alertSubscriptions()
        ->where('type', 'security')
        ->where('channel', 'email')
        ->where('is_active', true)
        ->exists();
    $workspaceLogoUrl = $workspace->logo_path
        ? Storage::disk(config('filesystems.evidence_disk'))->url($workspace->logo_path)
        : null;
@endphp

<div
    x-data="{
        isOpen: false,
        saving: false,
        securitySaving: false,
        autoSave: @js($workspace->auto_save),
        compactLayout: @js($workspace->compact_layout),
        securityAlerts: @js($securityAlertsSubscribed),
        language: @js($workspace->language),
        logoUrl: @js($workspaceLogoUrl),
        pricingOpen: false,
        successMessage: '',
        errorMessage: '',
        fieldErrors: {},
        saveTimer: null,
        popoverTop: 0,
        popoverLeft: 0,
        openSettings(anchor) {
            const bounds = anchor.getBoundingClientRect();
            const popoverWidth = Math.min(380, window.innerWidth - 16);
            const popoverHeight = Math.min(window.innerHeight * 0.72, 600);
            let left = bounds.right;

            if (left + popoverWidth > window.innerWidth - 8) {
                left = bounds.left - popoverWidth;
            }

            this.popoverLeft = Math.max(8, left);
            this.popoverTop = Math.max(8, Math.min(bounds.top, window.innerHeight - popoverHeight - 8));
            this.isOpen = true;
            this.successMessage = '';
            this.errorMessage = '';
            this.$nextTick(() => this.$refs.closeButton.focus());
        },
        closeSettings() {
            this.isOpen = false;
            this.pricingOpen = false;
        },
        scheduleSave(force = false) {
            if (!this.autoSave && !force) return;
            clearTimeout(this.saveTimer);
            this.saveTimer = window.setTimeout(() => this.saveSettings(), 800);
        },
        async saveSettings() {
            if (this.saving || !this.$refs.settingsForm) return;
            this.saving = true;
            this.errorMessage = '';
            this.fieldErrors = {};

            try {
                const response = await fetch(this.$refs.settingsForm.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: new FormData(this.$refs.settingsForm),
                });
                const result = await response.json();

                if (!response.ok) {
                    this.fieldErrors = result.errors || {};
                    this.errorMessage = Object.values(this.fieldErrors)[0]?.[0] || result.message || @js(__('Could not save workspace settings.'));
                    return;
                }

                this.successMessage = result.message;
                this.logoUrl = result.workspace.logo_url || this.logoUrl;
                document.body.classList.toggle('compact-layout', result.workspace.compact_layout);
                document.documentElement.lang = result.workspace.language;
                document.documentElement.dir = result.workspace.direction;
                localStorage.setItem('locale', result.workspace.language);
                localStorage.setItem('dir', result.workspace.direction);
                window.dispatchEvent(new CustomEvent('workspace-settings-saved', { detail: result.workspace }));
            } catch (error) {
                this.errorMessage = @js(__('Could not save workspace settings.'));
            } finally {
                this.saving = false;
            }
        },
        async toggleSecurityAlerts() {
            const nextValue = !this.securityAlerts;
            this.securitySaving = true;
            this.errorMessage = '';

            try {
                const response = await fetch(@js(route('settings.subscriptions.store')), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ type: 'security', channel: 'email', is_active: nextValue }),
                });
                const result = await response.json();

                if (!response.ok) throw new Error(result.message || '');
                this.securityAlerts = result.is_active;
            } catch (error) {
                this.errorMessage = @js(__('Could not update security alerts.'));
            } finally {
                this.securitySaving = false;
            }
        },
        previewLogo(event) {
            const file = event.target.files[0];
            if (file) this.logoUrl = URL.createObjectURL(file);
        },
        languageFlag() {
            return this.language === 'ar'
                ? @js(asset('images/flag-sa.svg'))
                : @js(asset('images/flag-us.svg'));
        },
    }"
    @workspace-settings-open.window="openSettings($event.detail.anchor)"
    @keydown.escape.window="closeSettings()"
    >
    <div x-cloak x-show="isOpen" x-transition.origin.top.left
        :style="{ top: popoverTop + 'px', left: popoverLeft + 'px' }"
        @click.outside="closeSettings()"
        class="fixed z-999999 w-[min(380px,calc(100vw-16px))]">
        <section class="flex max-h-[min(72vh,600px)] flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-theme-xl dark:border-gray-700 dark:bg-gray-900"
            role="dialog" aria-modal="false" aria-labelledby="workspace-settings-title">
            <header class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-blue-light-600 dark:text-blue-light-300">{{ __('Workspace') }}</p>
                    <h2 id="workspace-settings-title" class="mt-1 text-base font-semibold text-gray-900 dark:text-white">{{ __('Workspace settings') }}</h2>
                </div>
                <button type="button" x-ref="closeButton" @click="closeSettings()"
                    class="grid size-8 shrink-0 place-items-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white"
                    aria-label="{{ __('Close workspace settings') }}" title="{{ __('Close workspace settings') }}">
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
                </button>
            </header>

            <form x-ref="settingsForm" method="POST" action="{{ route('settings.workspace') }}" enctype="multipart/form-data"
                @submit.prevent="saveSettings()"
                @input.debounce.800ms="scheduleSave()"
                @change.debounce.800ms="scheduleSave()"
                class="flex min-h-0 flex-1 flex-col">
                @csrf
                @method('PATCH')
                <input type="hidden" name="timezone" value="{{ $workspace->timezone }}">
                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-3">
                    <section>
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">{{ __('General') }}</h3>
                        <div class="space-y-4">
                            <div>
                                <label for="workspace-name" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Workspace Name') }}</label>
                                <input id="workspace-name" name="name" value="{{ $workspace->name }}" required maxlength="120"
                                    @readonly(! $canRenameWorkspace)
                                    @input.debounce.800ms="scheduleSave()"
                                    class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 read-only:bg-gray-50 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:read-only:bg-white/5">
                                @if ($nameAvailableAt && ! $canRenameWorkspace)
                                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Workspace name can next be changed on') }} {{ $nameAvailableAt->toFormattedDateString() }}.</p>
                                @else
                                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Workspace names can be changed once every 7 days.') }}</p>
                                @endif
                                <p x-show="fieldErrors.name" x-text="fieldErrors.name?.[0]" class="mt-1 text-xs text-error-600" role="alert"></p>
                            </div>

                            <div>
                                <label for="workspace-description" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Workspace Description') }}</label>
                                <textarea id="workspace-description" name="description" rows="2" maxlength="1000"
                                    placeholder="{{ __('Add a short description of this workspace.') }}"
                                    class="w-full resize-y rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-950 dark:text-white">{{ $workspace->description }}</textarea>
                                @if (! $workspace->description)
                                    <p class="mt-1.5 text-xs text-blue-light-700 dark:text-blue-light-300">{{ __('Add a description to help your team identify this workspace.') }}</p>
                                @endif
                            </div>

                            <div>
                                <p class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Workspace Logo') }}</p>
                                <div class="flex items-center gap-3">
                                    <div class="grid size-12 shrink-0 place-items-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800">
                                        <img x-show="logoUrl" :src="logoUrl" alt="{{ __('Workspace logo preview') }}" class="size-full object-cover">
                                        <svg x-show="!logoUrl" class="size-5 text-gray-400" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2.5" y="2.5" width="15" height="15" rx="3" stroke="currentColor" stroke-width="1.4"/><path d="M6 13.5 9 10.5l2 2 1.5-1.5 2.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <label for="workspace-logo" class="inline-flex cursor-pointer items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">{{ $workspace->logo_path ? __('Change logo') : __('Add logo') }}</label>
                                        <input id="workspace-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="previewLogo($event)">
                                        @if (! $workspace->logo_path)
                                            <p class="mt-1 text-xs text-blue-light-700 dark:text-blue-light-300">{{ __('Add a logo to make this workspace easier to recognize.') }}</p>
                                        @else
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('PNG, JPG, or WebP. Maximum 2 MB.') }}</p>
                                        @endif
                                    </div>
                                </div>
                                <p x-show="fieldErrors.logo" x-text="fieldErrors.logo?.[0]" class="mt-1 text-xs text-error-600" role="alert"></p>
                            </div>

                            <div class="grid gap-3">
                                <div>
                                    <label for="workspace-currency" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Default Currency') }}</label>
                                    <div class="flex gap-2">
                                        <select id="workspace-currency" name="currency" class="h-10 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                                            @foreach (['USD', 'EUR', 'GBP', 'AED', 'SAR'] as $currency)
                                                <option value="{{ $currency }}" @selected($workspace->currency === $currency)>{{ $currency }}</option>
                                            @endforeach
                                        </select>
                                        <div class="relative" @click.outside="pricingOpen = false">
                                            <button type="button" @click="pricingOpen = !pricingOpen" aria-label="{{ __('Show current plan') }}" aria-haspopup="true" :aria-expanded="pricingOpen.toString()"
                                                class="h-10 rounded-lg bg-blue-light-50 px-3 text-sm font-semibold text-blue-light-700 hover:bg-blue-light-100 dark:bg-blue-light-500/15 dark:text-blue-light-300 dark:hover:bg-blue-light-500/25">{{ __('Pricing') }}</button>
                                            <div x-cloak x-show="pricingOpen" x-transition class="absolute end-0 top-full z-10 mt-2 w-52 rounded-lg border border-gray-200 bg-white p-3 shadow-theme-lg dark:border-gray-700 dark:bg-gray-900" role="status">
                                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Current plan') }}</p>
                                                <p class="mt-1 text-sm font-semibold capitalize text-gray-900 dark:text-white">{{ str($workspace->plan)->headline() }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label for="workspace-language" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Workspace Language') }}</label>
                                    <div class="flex items-center gap-2">
                                        <img :src="languageFlag()" alt="" class="size-5 shrink-0 rounded-sm object-cover">
                                        <select id="workspace-language" name="language" x-model="language" class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                                            @foreach ($supportedLocales as $locale => $metadata)
                                                <option value="{{ $locale }}">{{ $metadata['native'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="border-t border-gray-200 pt-4 dark:border-gray-800">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Preferences') }}</h3>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('Security Alerts') }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Receive security alert notifications by email.') }}</p>
                                </div>
                                <button type="button" @click="toggleSecurityAlerts()" :disabled="securitySaving"
                                    class="shrink-0 rounded-md px-3 py-2 text-sm font-semibold transition-colors disabled:opacity-50"
                                    :class="securityAlerts ? 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-300' : 'bg-blue-light-50 text-blue-light-700 hover:bg-blue-light-100 dark:bg-blue-light-500/15 dark:text-blue-light-300'"
                                    x-text="securityAlerts ? @js(__('Subscribed')) : @js(__('Subscribe'))"></button>
                            </div>

                            <label class="flex items-center justify-between gap-4">
                                <span>
                                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('Compact Layout') }}</span>
                                    <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ __('Use tighter spacing across the workspace.') }}</span>
                                </span>
                                <span class="relative inline-flex shrink-0 items-center">
                                    <input type="hidden" name="compact_layout" value="0">
                                    <input type="checkbox" name="compact_layout" value="1" x-model="compactLayout" class="peer sr-only">
                                    <span class="h-6 w-11 rounded-full bg-gray-200 transition-colors peer-checked:bg-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500 peer-focus-visible:ring-offset-2 dark:bg-gray-700"></span>
                                    <span class="pointer-events-none absolute start-0.5 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5 rtl:peer-checked:-translate-x-5"></span>
                                </span>
                            </label>

                            <label class="flex items-center justify-between gap-4">
                                <span>
                                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('Auto-Save') }}</span>
                                    <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ __('Save changes in this modal automatically.') }}</span>
                                </span>
                                <span class="relative inline-flex shrink-0 items-center">
                                    <input type="hidden" name="auto_save" value="0">
                                    <input type="checkbox" name="auto_save" value="1" x-model="autoSave" @change.stop="scheduleSave(true)" class="peer sr-only">
                                    <span class="h-6 w-11 rounded-full bg-gray-200 transition-colors peer-checked:bg-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500 peer-focus-visible:ring-offset-2 dark:bg-gray-700"></span>
                                    <span class="pointer-events-none absolute start-0.5 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5 rtl:peer-checked:-translate-x-5"></span>
                                </span>
                            </label>
                        </div>
                    </section>

                    <p x-show="successMessage" x-text="successMessage" class="rounded-md bg-success-50 px-3 py-2 text-sm text-success-700 dark:bg-success-500/10 dark:text-success-300" role="status"></p>
                    <p x-show="errorMessage" x-text="errorMessage" class="rounded-md bg-error-50 px-3 py-2 text-sm text-error-700 dark:bg-error-500/10 dark:text-error-300" role="alert"></p>
                </div>

                <footer class="flex items-center justify-between gap-3 border-t border-gray-200 px-4 py-3 dark:border-gray-800">
                    <span x-show="autoSave" class="text-xs text-gray-500 dark:text-gray-400">{{ __('Changes save automatically.') }}</span>
                    <span x-show="!autoSave" class="text-xs text-gray-500 dark:text-gray-400">{{ __('Changes are not saved until you save.') }}</span>
                    <button type="submit" :disabled="saving" class="inline-flex h-10 min-w-28 items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60 dark:bg-brand-500 dark:hover:bg-brand-400">
                        <span x-show="!saving">{{ __('Save Changes') }}</span>
                        <span x-show="saving">{{ __('Saving...') }}</span>
                    </button>
                </footer>
            </form>
        </section>
    </div>
</div>