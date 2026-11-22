<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BestLapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $track_id
 * @property int $car_id
 * @property int $driver_id
 * @property int|null $lap_id
 * @property int $lap_ms
 * @property Carbon $achieved_at
 * @property-read Track $track
 * @property-read Car $car
 * @property-read Driver $driver
 */
#[Fillable(['track_id', 'car_id', 'driver_id', 'lap_id', 'lap_ms', 'achieved_at'])]
class BestLap extends Model
{
    /** @use HasFactory<BestLapFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lap_ms' => 'integer',
            'achieved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Track, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /**
     * @return BelongsTo<Car, $this>
     */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
