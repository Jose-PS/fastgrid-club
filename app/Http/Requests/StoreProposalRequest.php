<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Car;
use App\Models\Track;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Stringable;

class StoreProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|Stringable|Closure|string>>
     */
    public function rules(): array
    {
        return [
            'track_id' => ['bail', 'required', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                if (! Track::query()->proposable()->whereKey((int) $value)->exists()) {
                    $fail(__('props.errTrack'));
                }
            }],
            'cars' => ['bail', 'required', 'array', 'min:1'],
            'cars.*' => ['integer'],
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['cars', 'cars.*'])) {
                    return;
                }

                $carIds = array_map('intval', (array) $this->input('cars'));

                if (count(array_unique($carIds)) !== count($carIds)
                    || Car::query()->proposable()->whereKey($carIds)->count() !== count($carIds)) {
                    $validator->errors()->add('cars', __('props.errCarsInvalid'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'track_id.required' => __('props.errTrack'),
            'track_id.integer' => __('props.errTrack'),
            'cars.required' => __('props.errCars'),
            'cars.array' => __('props.errCars'),
            'cars.min' => __('props.errCars'),
            'cars.*.integer' => __('props.errCarsInvalid'),
        ];
    }

    /**
     * @return array<int, int>
     */
    public function carIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('cars')));
    }
}
