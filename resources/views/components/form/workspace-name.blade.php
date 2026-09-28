@props([
    'name',
    'value' => '',
    'label' => 'Workspace name',
    'placeholder' => '',
])

<div x-data='workspaceNameAvailability(@js(route("workspace.name.availability")), @js($value))'>
    <label for="{{ $name }}" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</label>
    <div class="relative">
        <input id="{{ $name }}" name="{{ $name }}" type="text" value="{{ $value }}" x-model="name" @input="check"
            aria-describedby="{{ $name }}-availability"
            :aria-invalid="status === 'taken'"
            placeholder="{{ $placeholder }}" required maxlength="120"
            class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 pe-11 text-sm text-gray-800 transition focus:border-brand-400 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
            :class="{ 'border-blue-light-500 focus:border-blue-light-500 focus:ring-blue-light-500/10': status === 'available', 'border-error-500 focus:border-error-500 focus:ring-error-500/10': status === 'taken' }">
        <span x-show="status === 'available'" x-cloak class="absolute inset-y-0 end-0 flex items-center pe-3 text-blue-light-600 dark:text-blue-light-400" aria-hidden="true" title="Workspace name available">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m4.5 10.2 3.4 3.4 7.6-7.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
        </span>
        <span x-show="status === 'checking'" x-cloak class="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400" aria-hidden="true">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" opacity=".25"/><path d="M17 10a7 7 0 0 0-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </span>
        <span x-show="status === 'taken'" x-cloak class="absolute inset-y-0 end-0 flex items-center pe-3 text-error-500" aria-hidden="true">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none"><path d="m6 6 8 8m0-8-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
        </span>
    </div>
    <p id="{{ $name }}-availability" class="mt-1 min-h-4 text-xs" aria-live="polite">
        <span x-show="status === 'available'" x-cloak class="text-blue-light-600 dark:text-blue-light-400">This workspace name is available.</span>
        <span x-show="status === 'taken'" x-cloak class="text-error-600 dark:text-error-400">That workspace name is already in use.</span>
        <span x-show="status === 'unavailable'" x-cloak class="text-gray-500 dark:text-gray-400">Could not check availability. We will check when you save.</span>
    </p>
</div>
