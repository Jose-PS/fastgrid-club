<?php

declare(strict_types=1);

namespace App\Models;

use App\Integration\Server\Log\SessionType;
use Database\Factories\ServerSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_id
 * @property SessionType $type
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int|null $track_id
 * @property int|null $week_id
 * @property int|null $cycle_index
 * @property string $source_line_hash
 * @property-read Server $server
 * @property-read Track|null $track
 * @property-read Week|null $week
 */
#[Fillable(['server_id', 'type', 'started_at', 'ended_at', 'track_id', 'week_id', 'cycle_index', 'source_line_hash'])]
class ServerSession extends Model
{
    /** @use HasFactory<ServerSessionFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SessionType::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'cycle_index' => 'integer',
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
     * @return BelongsTo<Track, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /**
     * @return BelongsTo<Week, $this>
     */
    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    /**
     * @return HasMany<Lap, $this>
     */
    public function laps(): HasMany
    {
        return $this->hasMany(Lap::class);
    }
}
