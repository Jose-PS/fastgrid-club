<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LogCursorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_id
 * @property list<string> $window_hashes
 * @property int|null $last_event_id
 * @property array<string, mixed> $processor_state
 * @property Carbon $last_read_at
 * @property Carbon|null $last_gap_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
#[Fillable(['server_id', 'window_hashes', 'last_event_id', 'processor_state', 'last_read_at', 'last_gap_at'])]
class LogCursor extends Model
{
    /** @use HasFactory<LogCursorFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_hashes' => 'array',
            'last_event_id' => 'integer',
            'processor_state' => 'array',
            'last_read_at' => 'datetime',
            'last_gap_at' => 'datetime',
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
