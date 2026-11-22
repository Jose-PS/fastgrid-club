<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $week_id
 * @property int|null $user_id
 * @property int $track_id
 * @property string $signature
 * @property Carbon|null $hidden_at
 * @property int|null $hidden_by
 * @property string|null $hidden_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $votes_count
 * @property-read Week $week
 * @property-read User|null $user
 * @property-read Track $track
 */
#[Fillable(['week_id', 'user_id', 'track_id', 'signature', 'hidden_at', 'hidden_by', 'hidden_reason'])]
class Proposal extends Model
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hidden_at' => 'datetime',
        ];
    }

    /**
     * @param  array<int, int>  $carIds
     */
    public static function signatureFor(int $trackId, array $carIds): string
    {
        $carIds = array_map('intval', $carIds);
        sort($carIds);

        return sha1($trackId.':'.implode(',', $carIds));
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function hasVotesFromOthers(int $userId): bool
    {
        return $this->votes()
            ->where(fn (Builder $query) => $query->whereNull('user_id')->orWhere('user_id', '!=', $userId))
            ->exists();
    }

    /**
     * @return BelongsTo<Week, $this>
     */
    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
        return $this->belongsToMany(Car::class, 'proposal_cars');
    }

    /**
     * @return HasMany<Vote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }
}
