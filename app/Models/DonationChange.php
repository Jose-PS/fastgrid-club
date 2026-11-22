<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DonationChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $donation_period_id
 * @property string $field
 * @property int $old_cents
 * @property int $new_cents
 * @property int|null $changed_by
 * @property Carbon|null $created_at
 * @property-read DonationPeriod $period
 * @property-read User|null $changedBy
 */
#[Fillable(['donation_period_id', 'field', 'old_cents', 'new_cents', 'changed_by'])]
class DonationChange extends Model
{
    /** @use HasFactory<DonationChangeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_cents' => 'integer',
            'new_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DonationPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(DonationPeriod::class, 'donation_period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
