<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Stringable;

class DestroyAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('confirmation'))) {
            $this->merge(['confirmation' => trim($this->input('confirmation'))]);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|Stringable|string>>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', Rule::in($this->acceptedConfirmations())],
            'hide_laps' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function acceptedConfirmations(): array
    {
        $words = array_map(
            fn (string $locale): string => __('account.danger.deleteWord', [], $locale),
            config('mvp.locales'),
        );

        return array_values(array_filter([...$words, $this->user()?->name]));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.required' => __('account.danger.confirmationMismatch'),
            'confirmation.in' => __('account.danger.confirmationMismatch'),
        ];
    }
}
