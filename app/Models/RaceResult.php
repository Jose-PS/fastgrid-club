<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResultSource;
use Database\Factories\RaceResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_session_id
 * @property int|null $week_id
 * @property int|null $cycle_index
 * @property int $driver_id
 * @property int|null $car_id
 * @property int $position
 * @property int $laps_completed
 * @property int|null $total_ms
 * @property int|null $gap_ms
 * @property bool $lapped
 * @property int|null $best_lap_ms
 * @property int|null $grid_position
 * @property bool $classified
 * @property ResultSource $source
 * @property Carbon $created_at
 * @property-read ServerSession $session
 * @property-read Driver $driver
 * @property-read Car|null $car
 */
#[Fillable(['server_session_id', 'week_id', 'cycle_index', 'driver_id', 'car_id', 'position', 'laps_completed', 'total_ms', 'gap_ms', 'lapped', 'best_lap_ms', 'grid_position', 'classified', 'source', 'created_at'])]
class RaceResult extends Model
{
    /** @use HasFactory<RaceResultFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cycle_index' => 'integer',
            'position' => 'integer',
            'laps_completed' => 'integer',
            'total_ms' => 'integer',
            'gap_ms' => 'integer',
            'lapped' => 'boolean',
            'best_lap_ms' => 'integer',
            'grid_position' => 'integer',
            'classified' => 'boolean',
            'source' => ResultSource::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ServerSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ServerSession::class, 'server_session_id');
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<Car, $this>
     */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }
}
