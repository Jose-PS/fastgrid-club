<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CatalogSyncFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_id
 * @property array{cars: array{created: int, updated: int, deactivated: int}, tracks: array{created: int, updated: int, deactivated: int}, unpaired_race_tracks: list<string>} $summary
 * @property Carbon $started_at
 * @property Carbon $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
#[Fillable(['server_id', 'summary', 'started_at', 'finished_at'])]
class CatalogSync extends Model
{
    /** @use HasFactory<CatalogSyncFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'summary' => 'array',
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
}
