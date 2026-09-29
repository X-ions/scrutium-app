@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @if ($accounts->isEmpty())
        <x-socialhub.empty-state
            title="Connect an account before composing"
            description="Publishing needs at least one connected social account. Once connected you can write once and send to several networks."
            actionLabel="Go to social accounts"
            actionUrl="{{ route('socialhub.accounts.index') }}"
            icon="user-profile" />
    @else
        @php
            $existing = $post->exists
                ? $post->variants->keyBy('social_account_id')
                : collect();
        @endphp

        <form method="POST"
            action="{{ $post->exists ? route('socialhub.posts.update', $post) : route('socialhub.posts.store') }}"
            x-data="{ selected: {{ $selectedIds->toJson() }} }"
            class="space-y-6">
            @csrf
            @if ($post->exists)
                @method('PUT')
            @endif

            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <label for="title" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Internal title</label>
                <input type="text" id="title" name="title" value="{{ old('title', $post->title) }}"
                    placeholder="Only your team sees this"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-white/5 dark:text-white/90">

                <label for="notes" class="mt-4 mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Internal notes</label>
                <textarea id="notes" name="notes" rows="2"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-white/5 dark:text-white/90">{{ old('notes', $post->notes) }}</textarea>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Publish to</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Each network gets its own caption and media selection.</p>

                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($accounts as $account)
                        <label @class([
                            'inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition-colors',
                            'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' => in_array($account['id'], $selectedIds->all(), true),
                            'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5' => ! in_array($account['id'], $selectedIds->all(), true),
                        ])>
                            <input type="checkbox" name="enabled_accounts[]"
                                value="{{ $account['id'] }}" x-model.number="selected"
                                class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-white/5">
                            <span>{{ $account['platform'] }}</span>
                            <span class="text-xs font-normal opacity-70">{{ $account['label'] }}</span>
                        </label>
                    @endforeach
                </div>

                @error('variants')
                    <p class="mt-2 text-sm text-error-600 dark:text-error-400">{{ $message }}</p>
                @enderror
            </div>

            @foreach ($accounts as $account)
                @php
                    $variant = $existing->get($account['id']);
                    $field = 'variants['.$accounts->search($account).']';
                    $caption = old($field.'[caption]', $variant?->caption);
                    $selectedMedia = collect(old($field.'[media_ids]', $variant?->media_ids ?? []))->map('intval')->all();
                    $maxCaption = $account['max_caption'];
                @endphp

                <div x-cloak x-show="selected.includes({{ $account['id'] }})"
                    class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <x-socialhub.platform-chip :provider="$account['provider']" size="md" />
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $account['label'] }}</span>
                        </div>
                        @if ($variant)
                            <x-socialhub.status-badge :status="$variant->statusEnum()" />
                        @endif
                    </div>

                    <input type="hidden" name="{{ $field }}[social_account_id]" value="{{ $account['id'] }}">

                    <label for="caption-{{ $account['id'] }}" class="mt-3 mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
                        Caption
                        <span class="font-normal text-gray-400">(max {{ number_format($maxCaption) }} characters)</span>
                    </label>
                    <textarea id="caption-{{ $account['id'] }}" name="{{ $field }}[caption]" rows="4" maxlength="{{ $maxCaption }}"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-white/5 dark:text-white/90">{{ $caption }}</textarea>

                    @if ($account['supports_hashtags'])
                        <label for="hashtags-{{ $account['id'] }}" class="mt-3 mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Hashtags</label>
                        <input id="hashtags-{{ $account['id'] }}" type="text" name="{{ $field }}[hashtags]"
                            value="{{ old($field.'[hashtags]', collect($variant?->hashtags ?? [])->implode(', ')) }}"
                            placeholder="one, two, three"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                    @else
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                            {{ $account['platform'] }} has no separate hashtag field — include them in the caption.
                        </p>
                    @endif

                    <div class="mt-4">
                        <p class="mb-2 text-xs font-medium text-gray-600 dark:text-gray-300">
                            Media
                            <span class="font-normal text-gray-400">(max {{ $account['max_media'] }} files)</span>
                        </p>

                        @if ($media->isEmpty())
                            <a href="{{ route('socialhub.media.index') }}" class="text-xs font-medium text-brand-600 dark:text-brand-300">Upload media first</a>
                        @else
                            <div class="flex flex-wrap gap-2">
                                @foreach ($media as $asset)
                                    <label class="relative cursor-pointer" title="{{ $asset->filename }}">
                                        <input type="checkbox" name="{{ $field }}[media_ids][]" value="{{ $asset->id }}"
                                            @checked(in_array($asset->id, $selectedMedia, true))
                                            class="peer sr-only">
                                        <img src="{{ $asset->thumbnail_path ? Storage::disk($asset->storage_disk)->url($asset->thumbnail_path) : Storage::disk($asset->storage_disk)->url($asset->storage_path) }}"
                                            alt="{{ $asset->alt_text ?: $asset->filename }}" loading="lazy"
                                            class="h-16 w-16 rounded-lg border-2 border-transparent object-cover peer-checked:border-brand-500">
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" name="publish_now" value="1"
                    class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-700">Publish now</button>

                <div class="flex items-center gap-2">
                    <label for="scheduled_at" class="sr-only">Schedule for</label>
                    <input type="datetime-local" id="scheduled_at" name="scheduled_at"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                    <button type="submit"
                        class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Schedule</button>
                </div>

                <button type="submit"
                    class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Save draft</button>
            </div>
        </form>
    @endif
@endsection
