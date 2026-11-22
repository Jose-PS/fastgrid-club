<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UpdateDefaultConfigRequest extends StoreProposalRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'custom_title' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function customTitle(): ?string
    {
        $title = $this->validated('custom_title');

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }
}
