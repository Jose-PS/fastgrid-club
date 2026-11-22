<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConfigApplicationStatus;
use Database\Factories\ConfigApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_id
 * @property int|null $week_id
 * @property string|null $block_key
 * @property string|null $block
 * @property int $attempt
 * @property ConfigApplicationStatus $status
 * @property string|null $error
 * @property array<string, mixed> $snapshot
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read Week|null $week
 */
#[Fillable(['server_id', 'week_id', 'block_key', 'block', 'attempt', 'status', 'error', 'snapshot', 'started_at', 'finished_at'])]
class ConfigApplication extends Model
{
    /** @use HasFactory<ConfigApplicationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'status' => ConfigApplicationStatus::class,
            'snapshot' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * @return BelongsTo<Week, $this>
     */
    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }
}
