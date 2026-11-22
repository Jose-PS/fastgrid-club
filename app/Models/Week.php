<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConfigSource;
use App\Enums\WeekStatus;
use Database\Factories\WeekFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_id
 * @property int|null $max_players
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon $voting_closes_at
 * @property WeekStatus $status
 * @property int|null $track_id
 * @property ConfigSource|null $config_source
 * @property int|null $proposal_id
 * @property string|null $custom_title
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read Track|null $track
 * @property-read Proposal|null $proposal
 */
#[Fillable(['server_id', 'max_players', 'starts_at', 'ends_at', 'voting_closes_at', 'status', 'track_id', 'config_source', 'proposal_id', 'custom_title', 'resolved_at'])]
class Week extends Model
{
    /** @use HasFactory<WeekFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_players' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'voting_closes_at' => 'datetime',
            'status' => WeekStatus::class,
            'config_source' => ConfigSource::class,
            'resolved_at' => 'datetime',
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
     * @return BelongsToMany<Car, $this>
     */
    public function cars(): BelongsToMany
    {
        return $this->belongsToMany(Car::class, 'week_cars');
    }

    /**
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * @return HasMany<Proposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /**
     * @return HasMany<Vote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    public function acceptsVotesAt(DateTimeInterface $instant): bool
    {
        return $this->status === WeekStatus::Voting
            && $instant < $this->voting_closes_at->addSecond();
    }
}
