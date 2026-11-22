<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TrackMode;
use Carbon\CarbonInterface;
use Database\Factories\TrackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property TrackMode $mode
 * @property string $token
 * @property string $display
 * @property string $track
 * @property string $layout
 * @property string $event_name
 * @property string|null $country
 * @property int|null $length_m
 * @property string|null $svg_slug
 * @property int $max_pit_slot
 * @property int|null $paired_track_id
 * @property bool $active
 * @property bool $proposable
 * @property CarbonInterface|null $synced_at
 * @property-read Track|null $pairedTrack
 */
#[Fillable(['mode', 'token', 'display', 'track', 'layout', 'event_name', 'country', 'length_m', 'svg_slug', 'max_pit_slot', 'paired_track_id', 'active', 'proposable', 'synced_at'])]
class Track extends Model
{
    /** @use HasFactory<TrackFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => TrackMode::class,
            'length_m' => 'integer',
            'max_pit_slot' => 'integer',
            'active' => 'boolean',
            'proposable' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Track, $this>
     */
    public function pairedTrack(): BelongsTo
    {
        return $this->belongsTo(self::class, 'paired_track_id');
    }

    /**
     * @param  Builder<Track>  $query
     */
    #[Scope]
    protected function proposable(Builder $query): void
    {
        $query->where('mode', TrackMode::RaceWeekend)
            ->where('active', true)
            ->where('proposable', true)
            ->whereNotNull('paired_track_id');
    }
}
