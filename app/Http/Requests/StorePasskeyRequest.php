<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;

class StorePasskeyRequest extends PasskeyRegistrationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
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
