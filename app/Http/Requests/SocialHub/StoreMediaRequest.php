<?php

declare(strict_types=1);

namespace App\Http\Requests\SocialHub;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload validation. The rules mirror `config/socialhub.php` so the browser and
 * the server agree on the limit, but the server-side service is what actually
 * enforces it — it re-sniffs the file and rejects a spoofed type regardless of
 * what passed here.
 */
final class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('socialhub.media.max_size_kb', 2048);
        $mimes = implode(',', (array) config('socialhub.media.allowed_mime', []));

        return [
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file', 'mimetypes:'.$mimes, 'max:'.$maxKb],
            'folder' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = (int) ceil((int) config('socialhub.media.max_size_kb', 2048) / 1024);
        $allowed = implode(', ', (array) config('socialhub.media.allowed_mime', []));

        return [
            'files.required' => 'Choose at least one file to upload.',
            'files.*.mimetypes' => "Unsupported file type. Allowed: {$allowed}.",
            'files.*.max' => "Files must be {$maxMb} MB or smaller.",
        ];
    }
}
