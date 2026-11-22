<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DonationPlatform;
use App\Rules\DonationUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Stringable;

class UpdateDonationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $urls = $this->input('urls');

        if (is_array($urls)) {
            $this->merge(['urls' => array_map(fn (mixed $url): mixed => is_string($url) ? trim($url) : $url, $urls)]);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|Stringable|string>>
     */
    public function rules(): array
    {
        $rules = [
            'urls' => ['sometimes', 'array'],
            'goal_cents' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'covered_cents' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ];

        foreach (DonationPlatform::cases() as $platform) {
            $rules["urls.{$platform->value}"] = ['nullable', 'string', 'max:2048', new DonationUrl($platform)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'urls.ko_fi' => 'Ko-fi',
            'urls.patreon' => 'Patreon',
            'urls.paypal' => 'PayPal',
        ];
    }
}
