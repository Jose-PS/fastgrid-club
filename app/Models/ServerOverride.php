<?php

declare(strict_types=1);

namespace App\Models;

use App\Integration\Server\Data\ConfigPlan;
use Carbon\CarbonInterface;
use Database\Factories\ServerOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property int $server_id
 * @property int|null $week_id
 * @property array<string, mixed> $plan
 * @property Carbon|null $until
 * @property Carbon|null $ended_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read Week|null $week
 * @property-read User|null $creator
 */
#[Fillable(['key', 'server_id', 'week_id', 'plan', 'until', 'ended_at', 'created_by'])]
class ServerOverride extends Model
{
    /** @use HasFactory<ServerOverrideFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'until' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function blockKey(): string
    {
        return "override:{$this->key}";
    }

    public function configPlan(): ConfigPlan
    {
        return ConfigPlan::fromArray($this->plan);
    }

    public function isActiveAt(CarbonInterface $at): bool
    {
        return $this->ended_at === null && ($this->until === null || $this->until->greaterThan($at));
    }

    /**
     * @param  Builder<ServerOverride>  $query
     */
    #[Scope]
    protected function active(Builder $query, CarbonInterface $at): void
    {
        $query->whereNull('ended_at')
            ->where(fn (Builder $inner) => $inner->whereNull('until')->orWhere('until', '>', $at));
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
