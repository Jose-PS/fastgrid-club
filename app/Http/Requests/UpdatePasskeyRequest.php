<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Passkeys\Passkey;
use Stringable;

class UpdatePasskeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $passkey = $this->route('passkey');

        return $passkey instanceof Passkey && $this->user()?->can('update', $passkey) === true;
    }

    /**
     * @return array<string, array<int, ValidationRule|Stringable|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('account.passkeys.alias'),
        ];
    }
}
