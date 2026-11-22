<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IntegrationEventType;
use Database\Factories\IntegrationEventFactory;
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
 * @property IntegrationEventType $type
 * @property string $summary
 * @property Carbon|null $created_at
 * @property-read Server $server
 */
#[Fillable(['server_id', 'type', 'summary'])]
class IntegrationEvent extends Model
{
    /** @use HasFactory<IntegrationEventFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IntegrationEventType::class,
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
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(30));
    }
}
