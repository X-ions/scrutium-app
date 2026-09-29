<?php

declare(strict_types=1);

namespace App\Http\Requests\SocialHub;

use Illuminate\Foundation\Http\FormRequest;

final class SchedulePostRequest extends FormRequest
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
            'scheduled_at' => ['required', 'date', 'after:now'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_at.after' => 'Choose a time in the future.',
        ];
    }
}
