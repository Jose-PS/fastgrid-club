<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DonationPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $month
 * @property int $goal_cents
 * @property int $covered_cents
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $updatedBy
 * @property-read Collection<int, DonationChange> $changes
 */
#[Fillable(['month', 'goal_cents', 'covered_cents', 'updated_by'])]
class DonationPeriod extends Model
{
    /** @use HasFactory<DonationPeriodFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'goal_cents' => 'integer',
            'covered_cents' => 'integer',
        ];
    }

    public function percent(): int
    {
        if ($this->goal_cents <= 0) {
            return 0;
        }

        return min(100, intdiv($this->covered_cents * 100, $this->goal_cents));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return HasMany<DonationChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(DonationChange::class);
    }
}
