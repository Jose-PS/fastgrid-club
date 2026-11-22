<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_id
 * @property int|null $server_session_id
 * @property int $driver_id
 * @property int|null $car_id
 * @property int|null $track_id
 * @property int $lap_ms
 * @property int|null $lap_number
 * @property bool|null $is_valid
 * @property Carbon $recorded_at
 * @property string $source_hash
 * @property-read Server $server
 * @property-read ServerSession|null $session
 * @property-read Driver $driver
 * @property-read Car|null $car
 * @property-read Track|null $track
 */
#[Fillable(['server_id', 'server_session_id', 'driver_id', 'car_id', 'track_id', 'lap_ms', 'lap_number', 'is_valid', 'recorded_at', 'source_hash'])]
class Lap extends Model
{
    /** @use HasFactory<LapFactory> */
    use HasFactory, MassPrunable;

    public const RETENTION_DAYS = 90;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lap_ms' => 'integer',
            'lap_number' => 'integer',
            'is_valid' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
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

    /**
     * @return BelongsTo<Track, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('recorded_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
