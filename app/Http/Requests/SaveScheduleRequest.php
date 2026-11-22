<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\Schedule\SaveSchedule;
use App\Enums\RaceDurationType;
use App\Enums\WeatherType;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveScheduleRequest extends FormRequest
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
            'apply' => ['required', Rule::in([SaveSchedule::APPLY_TOMORROW, SaveSchedule::APPLY_NOW])],
            'segments' => ['required', 'array', 'min:1'],
            'segments.*' => ['array'],
            'segments.*.weekday' => ['required', 'integer', 'between:1,7'],
            'segments.*.starts_at' => ['required', 'date_format:H:i'],
            'segments.*.practice_minutes' => ['nullable', 'integer', 'between:1,600'],
            'segments.*.qualifying_minutes' => ['nullable', 'integer', 'between:1,120'],
            'segments.*.race_duration_type' => ['nullable', Rule::enum(RaceDurationType::class)],
            'segments.*.race_minutes' => ['nullable', 'integer', 'between:1,240'],
            'segments.*.race_laps' => ['nullable', 'integer', 'between:1,200'],
            'segments.*.start_hour' => ['required', 'integer', 'between:0,23'],
            'segments.*.weather' => ['required', Rule::enum(WeatherType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'apply.required' => __('admin.schedule.errApply'),
            'apply.in' => __('admin.schedule.errApply'),
            'segments.required' => __('admin.schedule.errSegments'),
            'segments.array' => __('admin.schedule.errSegments'),
            'segments.min' => __('admin.schedule.errSegments'),
            'segments.*.weekday.required' => __('admin.schedule.errWeekday'),
            'segments.*.weekday.integer' => __('admin.schedule.errWeekday'),
            'segments.*.weekday.between' => __('admin.schedule.errWeekday'),
            'segments.*.starts_at.required' => __('admin.schedule.errTime'),
            'segments.*.starts_at.date_format' => __('admin.schedule.errTime'),
            'segments.*.practice_minutes.integer' => __('admin.schedule.errPractice'),
            'segments.*.practice_minutes.between' => __('admin.schedule.errPractice'),
            'segments.*.qualifying_minutes.integer' => __('admin.schedule.errQualifying'),
            'segments.*.qualifying_minutes.between' => __('admin.schedule.errQualifying'),
            'segments.*.race_duration_type.enum' => __('admin.schedule.errRaceType'),
            'segments.*.race_minutes.integer' => __('admin.schedule.errRaceMinutes'),
            'segments.*.race_minutes.between' => __('admin.schedule.errRaceMinutes'),
            'segments.*.race_laps.integer' => __('admin.schedule.errRaceLaps'),
            'segments.*.race_laps.between' => __('admin.schedule.errRaceLaps'),
            'segments.*.start_hour.required' => __('admin.schedule.errHour'),
            'segments.*.start_hour.integer' => __('admin.schedule.errHour'),
            'segments.*.start_hour.between' => __('admin.schedule.errHour'),
            'segments.*.weather.required' => __('admin.schedule.errWeather'),
            'segments.*.weather.enum' => __('admin.schedule.errWeather'),
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $segments = $this->validationData()['segments'] ?? null;

                if (! is_array($segments)) {
                    return;
                }

                $seen = [];

                foreach ($segments as $index => $segment) {
                    if (! is_array($segment)) {
                        continue;
                    }

                    $key = ($segment['weekday'] ?? '').'|'.($segment['starts_at'] ?? '');

                    if (isset($segment['weekday'], $segment['starts_at'])) {
                        if (isset($seen[$key])) {
                            $validator->errors()->add("segments.$index.starts_at", __('admin.schedule.errDuplicate'));
                        }

                        $seen[$key] = true;
                    }

                    $this->validateSessions($validator, $index, $segment);
                }
            },
        ];
    }

    /**
     * @return array<int, array{weekday: int, starts_at: string, practice_minutes: int|null, qualifying_minutes: int|null, race_duration_type: string|null, race_minutes: int|null, race_laps: int|null, start_hour: int, weather: string}>
     */
    public function segments(): array
    {
        $segments = [];

        foreach ((array) $this->validated('segments') as $segment) {
            $type = $segment['race_duration_type'] ?? null;

            $segments[] = [
                'weekday' => (int) $segment['weekday'],
                'starts_at' => (string) $segment['starts_at'],
                'practice_minutes' => $this->nullableInt($segment['practice_minutes'] ?? null),
                'qualifying_minutes' => $this->nullableInt($segment['qualifying_minutes'] ?? null),
                'race_duration_type' => is_string($type) ? $type : null,
                'race_minutes' => $type === RaceDurationType::Time->value ? $this->nullableInt($segment['race_minutes'] ?? null) : null,
                'race_laps' => $type === RaceDurationType::Laps->value ? $this->nullableInt($segment['race_laps'] ?? null) : null,
                'start_hour' => (int) $segment['start_hour'],
                'weather' => (string) $segment['weather'],
            ];
        }

        return $segments;
    }

    public function apply(): string
    {
        return (string) $this->validated('apply');
    }

    /**
     * @param  array<array-key, mixed>  $segment
     */
    private function validateSessions(Validator $validator, int|string $index, array $segment): void
    {
        $type = $segment['race_duration_type'] ?? null;
        $minutes = $segment['race_minutes'] ?? null;
        $laps = $segment['race_laps'] ?? null;

        if (($segment['practice_minutes'] ?? null) === null && ($segment['qualifying_minutes'] ?? null) === null && $type === null) {
            $validator->errors()->add("segments.$index.practice_minutes", __('admin.schedule.errSession'));
        }

        if ($type === RaceDurationType::Time->value) {
            if ($minutes === null) {
                $validator->errors()->add("segments.$index.race_minutes", __('admin.schedule.errRaceMinutes'));
            }

            if ($laps !== null) {
                $validator->errors()->add("segments.$index.race_laps", __('admin.schedule.errRaceLaps'));
            }
        } elseif ($type === RaceDurationType::Laps->value) {
            if ($laps === null) {
                $validator->errors()->add("segments.$index.race_laps", __('admin.schedule.errRaceLaps'));
            }

            if ($minutes !== null) {
                $validator->errors()->add("segments.$index.race_minutes", __('admin.schedule.errRaceMinutes'));
            }
        } elseif ($type === null && ($minutes !== null || $laps !== null)) {
            $validator->errors()->add("segments.$index.race_duration_type", __('admin.schedule.errRaceType'));
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
