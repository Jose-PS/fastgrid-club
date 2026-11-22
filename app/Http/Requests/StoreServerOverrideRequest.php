<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\Servers\ApplyServerOverride;
use App\Enums\InitialGrip;
use App\Enums\RaceDurationType;
use App\Enums\ServerType;
use App\Enums\TrackMode;
use App\Enums\TuningType;
use App\Enums\WeatherBehaviour;
use App\Enums\WeatherType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServerOverrideRequest extends FormRequest
{
    private const SESSIONS = ['qualify', 'warmup', 'race'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'server_name' => ['required', 'string', 'max:120'],
            'max_players' => ['required', 'integer', 'between:1,100'],
            'cycle_enabled' => ['required', 'boolean'],
            'server_type' => ['required', Rule::enum(ServerType::class)],
            'tuning_type' => ['required', Rule::enum(TuningType::class)],
            'driver_password' => ['nullable', 'string', 'max:64'],
            'admin_password' => ['nullable', 'string', 'max:64'],
            'spectator_password' => ['nullable', 'string', 'max:64'],
            'clear_driver_password' => ['sometimes', 'boolean'],
            'clear_admin_password' => ['sometimes', 'boolean'],
            'clear_spectator_password' => ['sometimes', 'boolean'],
            'mode' => ['required', Rule::enum(TrackMode::class)],
            'track_id' => ['required', 'integer', Rule::exists('tracks', 'id')->where('active', true)->where('mode', TrackMode::RaceWeekend->value)],
            'weather' => ['required', Rule::enum(WeatherType::class)],
            'weather_behaviour' => ['required', Rule::enum(WeatherBehaviour::class)],
            'initial_grip' => ['required', Rule::enum(InitialGrip::class)],
            'car_ids' => ['required', 'array', 'min:1'],
            'car_ids.*' => ['integer', 'distinct', Rule::exists('cars', 'id')->where('active', true)],
            'until' => ['nullable', 'date_format:'.ApplyServerOverride::UNTIL_FORMAT],
            'sessions' => ['required', 'array'],
            'sessions.practice' => ['required', 'array'],
        ];

        return $rules + $this->sessionRules('practice', 'required') + $this->otherSessionRules();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function otherSessionRules(): array
    {
        $presence = $this->input('mode') === TrackMode::RaceWeekend->value ? 'required' : 'nullable';
        $rules = [];

        foreach (self::SESSIONS as $session) {
            $rules["sessions.{$session}"] = [$presence, 'array'];
            $rules += $this->sessionRules($session, $presence);
        }

        return $rules + [
            'sessions.race.duration_type' => [$presence, Rule::enum(RaceDurationType::class)],
            'sessions.race.laps' => [Rule::requiredIf($presence === 'required' && $this->input('sessions.race.duration_type') === RaceDurationType::Laps->value), 'nullable', 'integer', 'between:1,1000'],
            'sessions.race.min_waiting_for_players' => ['nullable', 'integer', 'between:0,3600'],
            'sessions.race.max_waiting_for_players' => ['nullable', 'integer', 'between:0,3600'],
            'sessions.race.mandatory_pitstop_enabled' => ['nullable', 'boolean'],
            'sessions.race.mandatory_pitstop_window_seconds' => ['nullable', 'integer', 'between:1,86400'],
            'sessions.race.mandatory_pitstop_refuel' => ['nullable', 'boolean'],
            'sessions.race.mandatory_pitstop_tyre_change' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function sessionRules(string $session, string $presence): array
    {
        return [
            "sessions.{$session}.length_sec" => [$presence, 'integer', 'between:0,86400'],
            "sessions.{$session}.hour" => [$presence, 'integer', 'between:0,23'],
            "sessions.{$session}.minute" => [$presence, 'integer', 'between:0,59'],
            "sessions.{$session}.time_multiplier" => [$presence, 'integer', 'between:1,60'],
            "sessions.{$session}.max_wait_to_box" => ['nullable', 'integer', 'between:0,3600'],
            "sessions.{$session}.overtime_waiting_next_session" => ['nullable', 'integer', 'between:0,3600'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overrideInput(): array
    {
        return $this->validated();
    }
}
