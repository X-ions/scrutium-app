@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="space-y-6">
        <form method="GET" class="flex flex-wrap items-end gap-2 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div>
                <label for="search" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Search</label>
                <input type="search" id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Filename, alt text, folder"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
            </div>
            <div>
                <label for="type" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Type</label>
                <select id="type" name="type" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                    <option value="">All</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="folder" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Folder</label>
                <select id="folder" name="folder" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                    <option value="">All folders</option>
                    @foreach ($folders as $folder)
                        <option value="{{ $folder }}" @selected(($filters['folder'] ?? '') === $folder)>{{ $folder }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2 pb-1">
                <label class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <input type="checkbox" name="unused" value="1" @checked(request()->boolean('unused')) class="rounded border-gray-300 text-brand-600">
                    Unused only
                </label>
            </div>
            <button class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Filter</button>
            <a href="{{ route('socialhub.media.index') }}" class="px-2 py-2 text-sm text-gray-500 dark:text-gray-400">Reset</a>

            <p class="ms-auto text-xs text-gray-500 dark:text-gray-400">
                {{ number_format($storageUsed / 1048576, 1) }} MB used
            </p>
        </form>

        @can('upload', App\Models\MediaAsset::class)
            <div x-data="{ open: false }" class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <button type="button" @click="open = !open" class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-700">
                    Upload media
                </button>

                <form x-cloak x-show="open" x-transition method="POST" action="{{ route('socialhub.media.store') }}"
                    enctype="multipart/form-data"
                    class="mt-4 space-y-3"
                    x-data="{ dragging: false }"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="dragging = false">
                    @csrf

                    <label :class="dragging ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10' : 'border-gray-300 dark:border-gray-700'"
                        class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-6 py-10 text-center">
                        <input type="file" name="files[]" multiple required
                            class="sr-only"
                            accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.mov,.webm">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Drop files here or click to choose</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Images and video up to {{ number_format((int) config('socialhub.media.max_size_kb', 2048) / 1024) }} MB. The file type is verified on the server.
                        </p>
                    </label>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label for="folder" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Folder</label>
                            <input type="text" id="folder" name="folder" placeholder="campaign-2026"
                                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                        </div>
                        <div>
                            <label for="tags" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Tags</label>
                            <input type="text" id="tags" name="tags" placeholder="product, launch"
                                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                        </div>
                        <div>
                            <label for="alt_text" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Alt text</label>
                            <input type="text" id="alt_text" name="alt_text"
                                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                        </div>
                    </div>

                    @error('files') <p class="text-sm text-error-600 dark:text-error-400">{{ $message }}</p> @enderror
                    @error('files.*') <p class="text-sm text-error-600 dark:text-error-400">{{ $message }}</p> @enderror

                    <button class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-700">Upload</button>
                </form>
            </div>
        @endcan

        @if ($media->isEmpty())
            <x-socialhub.empty-state title="Your library is empty" description="Upload images and video once, then reuse them across every network." icon="pages" />
        @else
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6">
                @foreach ($media as $asset)
                    <div class="group relative overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                        <a href="{{ route('socialhub.media.show', $asset) }}" class="block">
                            <img src="{{ $asset->thumbnail_path ? Storage::disk($asset->storage_disk)->url($asset->thumbnail_path) : Storage::disk($asset->storage_disk)->url($asset->storage_path) }}"
                                alt="{{ $asset->alt_text ?: $asset->filename }}" loading="lazy"
                                class="aspect-square w-full object-cover">
                        </a>
                        <div class="p-3">
                            <p class="truncate text-xs font-medium text-gray-800 dark:text-white/90" title="{{ $asset->filename }}">{{ $asset->filename }}</p>
                            <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                                {{ $asset->fileSizeForHumans() }}
                                @if ($asset->width) · {{ $asset->width }}×{{ $asset->height }} @endif
                                @if ($asset->duration) · {{ number_format((float) $asset->duration, 1) }}s @endif
                            </p>
                            @if ($asset->folder)
                                <p class="mt-1 truncate text-[11px] text-gray-400">{{ $asset->folder }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">{{ $media->links() }}</div>
        @endif
    </div>
@endsection
