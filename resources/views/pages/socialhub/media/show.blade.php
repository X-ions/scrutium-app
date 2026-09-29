@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            @if ($url)
                <img src="{{ $url }}" alt="{{ $asset->alt_text ?: $asset->filename }}" class="w-full rounded-xl object-contain">
            @else
                <div class="grid h-64 place-items-center rounded-xl bg-gray-50 text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    The stored file is not reachable on the configured disk.
                </div>
            @endif
        </div>

        <div class="space-y-4">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Details</h3>
                <dl class="mt-3 space-y-1.5 text-sm">
                    <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Filename</dt><dd class="text-gray-800 dark:text-white/90">{{ $asset->filename }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Type</dt><dd class="text-gray-800 dark:text-white/90">{{ $asset->mime_type }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Size</dt><dd class="text-gray-800 dark:text-white/90">{{ $asset->fileSizeForHumans() }}</dd></div>
                    @if ($asset->width)
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Dimensions</dt><dd class="text-gray-800 dark:text-white/90">{{ $asset->width }}×{{ $asset->height }}</dd></div>
                    @endif
                    @if ($asset->duration)
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Duration</dt><dd class="text-gray-800 dark:text-white/90">{{ number_format((float) $asset->duration, 1) }}s</dd></div>
                    @endif
                    <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Uploaded</dt><dd class="text-gray-800 dark:text-white/90">{{ $asset->created_at->format('j M Y, H:i') }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Disk</dt><dd class="text-gray-800 dark:text-white/90">{{ $asset->storage_disk }}</dd></div>
                </dl>
            </div>

            @can('update', $asset)
                <form method="POST" action="{{ route('socialhub.media.update', $asset) }}"
                    class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    @csrf
                    @method('PATCH')
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Edit</h3>
                    <label for="alt_text" class="mt-3 mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Alt text</label>
                    <input id="alt_text" type="text" name="alt_text" value="{{ $asset->alt_text }}"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                    <label for="folder" class="mt-3 mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Folder</label>
                    <input id="folder" type="text" name="folder" value="{{ $asset->folder }}"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                    <button class="mt-3 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Save</button>
                </form>
            @endcan

            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Usage</h3>
                @if ($usage === [])
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Not used in any post.</p>
                @else
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($usage as $item)
                            <li>
                                <a href="{{ route('socialhub.posts.show', $item['post_id']) }}" class="text-brand-600 hover:underline dark:text-brand-300">
                                    Post #{{ $item['post_id'] }}
                                </a>
                                <span class="text-gray-500 dark:text-gray-400"> — {{ $item['status'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @can('delete', $asset)
                <form method="POST" action="{{ route('socialhub.media.destroy', $asset) }}"
                    onsubmit="return confirm('Delete this file?')">
                    @csrf
                    @method('DELETE')
                    @if ($usage !== [])
                        <label class="mb-2 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                            <input type="checkbox" name="force" value="1" class="rounded border-gray-300 text-brand-600">
                            Delete even though it is used by {{ count($usage) }} post variant(s)
                        </label>
                    @endif
                    <button class="rounded-lg border border-error-200 px-3 py-2 text-sm font-medium text-error-600 hover:bg-error-50 dark:border-error-500/30">Delete</button>
                </form>
            @endcan
        </div>
    </div>
@endsection
