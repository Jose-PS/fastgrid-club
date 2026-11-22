<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Car;
use App\Models\Track;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCatalogProposableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'proposable' => [
                'required',
                'boolean',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $item = $this->route('track') ?? $this->route('car');

                    if ($this->boolean('proposable') && ($item instanceof Track || $item instanceof Car) && ! $item->active) {
                        $fail(__('admin.catalog.errInactive'));
                    }
                },
            ],
        ];
    }

    public function proposable(): bool
    {
        return $this->boolean('proposable');
    }
}
