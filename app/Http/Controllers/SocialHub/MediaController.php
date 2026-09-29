<?php

declare(strict_types=1);

namespace App\Http\Controllers\SocialHub;

use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Http\Requests\SocialHub\StoreMediaRequest;
use App\Models\MediaAsset;
use App\Services\Media\MediaLibraryService;
use App\Services\Media\MediaUploadException;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class MediaController extends Controller
{
    public function __construct(
        private readonly MediaLibraryService $library,
        private readonly MediaUploadService $uploads,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', MediaAsset::class);

        return view('pages.socialhub.media.index', [
            'title' => 'Media library',
            'media' => $this->library->search($request->only(['search', 'type', 'folder', 'tag', 'from', 'to', 'unused']), 24),
            'filters' => $request->only(['search', 'type', 'folder', 'tag']),
            'types' => MediaType::cases(),
            'folders' => $this->library->folders(),
            'tags' => $this->library->tags(),
            'stats' => $this->library->statistics(),
            'storageUsed' => $this->library->totalBytes(),
            'storageRemaining' => $this->library->remainingBytes((int) $request->user()->tenant_id),
        ]);
    }

    public function store(StoreMediaRequest $request): RedirectResponse
    {
        $this->authorize('upload', MediaAsset::class);

        $uploaded = 0;
        $refused = [];

        foreach ($request->file('files', []) as $index => $file) {
            try {
                $this->uploads->upload(
                    $file,
                    $request->user(),
                    $request->input('folder'),
                    (array) $request->input('tags', []),
                    $request->input('alt_text'),
                );

                $uploaded++;
            } catch (MediaUploadException $exception) {
                // One bad file must not abandon the good ones in the same
                // batch, so each refusal is collected and reported by name.
                $refused[$file->getClientOriginalName() ?? "file {$index}"] = $exception->userMessage();
            }
        }

        $message = sprintf('Uploaded %d file%s.', $uploaded, $uploaded === 1 ? '' : 's');

        foreach ($refused as $name => $reason) {
            $message .= sprintf(' %s: %s', $name, $reason);
        }

        return back()->with($refused === [] ? 'status' : 'error', $message);
    }

    public function update(Request $request, MediaAsset $mediaAsset): RedirectResponse
    {
        $this->authorize('update', $mediaAsset);

        $validated = $request->validate([
            'alt_text' => ['nullable', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $mediaAsset->update([
            'alt_text' => $validated['alt_text'] ?? null,
            'folder' => $validated['folder'] ?? null,
            'tags' => $validated['tags'] ?? [],
        ]);

        return back()->with('status', 'Media details updated.');
    }

    public function show(Request $request, MediaAsset $mediaAsset): View
    {
        $this->authorize('view', $mediaAsset);

        return view('pages.socialhub.media.show', [
            'title' => $mediaAsset->filename,
            'asset' => $mediaAsset,
            'usage' => $this->library->usageSummary((int) $mediaAsset->getKey()),
            'url' => $this->library->urlFor($mediaAsset),
            'thumbnailUrl' => $this->library->thumbnailUrlFor($mediaAsset),
        ]);
    }

    public function download(Request $request, MediaAsset $mediaAsset)
    {
        $this->authorize('view', $mediaAsset);

        return $this->library->download($mediaAsset);
    }

    public function destroy(Request $request, MediaAsset $mediaAsset): RedirectResponse
    {
        $this->authorize('delete', $mediaAsset);

        try {
            $this->library->delete($mediaAsset, $request->boolean('force'));
        } catch (MediaUploadException $exception) {
            throw ValidationException::withMessages(['media' => $exception->userMessage()]);
        }

        return back()->with('status', 'File deleted.');
    }

    /**
     * Bulk delete, reported per-file so a partial success is visible rather
     * than reported as a blanket success.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('delete', MediaAsset::class);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $result = $this->library->bulkDelete(
            array_map('intval', $validated['ids']),
            $request->boolean('force'),
        );

        $message = sprintf('Deleted %d file%s.', count($result['deleted']), count($result['deleted']) === 1 ? '' : 's');

        foreach ($result['refused'] as $reason) {
            $message .= ' '.$reason;
        }

        return back()->with($result['refused'] === [] ? 'status' : 'error', $message);
    }
}
