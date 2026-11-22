<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RaceDurationType;
use App\Enums\WeatherType;
use Database\Factories\ScheduleSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $schedule_version_id
 * @property int $weekday
 * @property string $starts_at
 * @property int|null $practice_minutes
 * @property int|null $qualifying_minutes
 * @property RaceDurationType|null $race_duration_type
 * @property int|null $race_minutes
 * @property int|null $race_laps
 * @property int $start_hour
 * @property WeatherType $weather
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ScheduleVersion $version
 */
#[Fillable(['schedule_version_id', 'weekday', 'starts_at', 'practice_minutes', 'qualifying_minutes', 'race_duration_type', 'race_minutes', 'race_laps', 'start_hour', 'weather'])]
class ScheduleSegment extends Model
{
    /** @use HasFactory<ScheduleSegmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'practice_minutes' => 'integer',
            'qualifying_minutes' => 'integer',
            'race_duration_type' => RaceDurationType::class,
            'race_minutes' => 'integer',
            'race_laps' => 'integer',
            'start_hour' => 'integer',
            'weather' => WeatherType::class,
        ];
    }

    /**
     * @return BelongsTo<ScheduleVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ScheduleVersion::class, 'schedule_version_id');
    }
}
