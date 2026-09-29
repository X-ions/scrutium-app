<?php

declare(strict_types=1);

namespace App\Http\Requests\SocialHub;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the composer.
 *
 * A variant may only target a social account the caller actually controls in
 * this workspace, which is what stops a crafted request from scheduling a post
 * onto somebody else's connected page.
 */
final class SavePostRequest extends FormRequest
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
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],

            'variants' => ['required', 'array', 'min:1', 'max:20'],
            'variants.*.social_account_id' => ['required', 'integer', Rule::exists('social_accounts', 'id')],
            'variants.*.caption' => ['nullable', 'string', 'max:63206'],
            'variants.*.hashtags' => ['nullable', 'array', 'max:30'],
            'variants.*.hashtags.*' => ['string', 'max:100'],
            'variants.*.media_ids' => ['nullable', 'array', 'max:10'],
            'variants.*.media_ids.*' => ['integer', Rule::exists('media_assets', 'id')],
            'variants.*.scheduled_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'variants.required' => 'Choose at least one network to publish to.',
            'variants.*.social_account_id.exists' => 'One of the selected accounts is not available in this workspace.',
            'variants.*.media_ids.*.exists' => 'One of the selected media files no longer exists.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = SocialAccount::query()
                ->where('tenant_id', $this->user()?->tenant_id)
                ->pluck('id')
                ->all();

            $seen = [];

            foreach ($this->input('variants', []) as $index => $variant) {
                $accountId = (int) ($variant['social_account_id'] ?? 0);

                if (! in_array($accountId, array_map('intval', $allowed), true)) {
                    $validator->errors()->add(
                        "variants.{$index}.social_account_id",
                        'That account is not connected to this workspace.',
                    );

                    continue;
                }

                if (isset($seen[$accountId])) {
                    $validator->errors()->add(
                        "variants.{$index}.social_account_id",
                        'Each account can only be targeted once per post.',
                    );
                }

                $seen[$accountId] = true;

                $this->validateVariant($validator, $index, $variant);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    private function validateVariant(Validator $validator, int $index, array $variant): void
    {
        $platform = SocialAccount::query()
            ->whereKey((int) ($variant['social_account_id'] ?? 0))
            ->value('provider');

        $platform = $platform instanceof SocialPlatform ? $platform : SocialPlatform::tryFrom((string) $platform);

        if ($platform === null) {
            return;
        }

        $caption = (string) ($variant['caption'] ?? '');

        if ($caption !== '' && mb_strlen($caption) > $platform->maxCaptionLength()) {
            $validator->errors()->add(
                "variants.{$index}.caption",
                sprintf('%s captions are limited to %s characters.', $platform->label(), number_format($platform->maxCaptionLength())),
            );
        }

        $mediaCount = count((array) ($variant['media_ids'] ?? []));

        if ($mediaCount > $platform->maxMediaAttachments()) {
            $validator->errors()->add(
                "variants.{$index}.media_ids",
                sprintf('%s allows at most %d media files per post.', $platform->label(), $platform->maxMediaAttachments()),
            );
        }

        $hashtags = array_filter((array) ($variant['hashtags'] ?? []));

        if ($hashtags !== [] && ! $platform->supportsNativeHashtags()) {
            $validator->errors()->add(
                "variants.{$index}.hashtags",
                sprintf('%s does not support hashtags as a separate field; add them to the caption instead.', $platform->label()),
            );
        }
    }
}
