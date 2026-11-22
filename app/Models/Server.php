<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServerDriverType;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property ServerDriverType $driver
 * @property string|null $base_url
 * @property string|null $credentials_ref
 * @property string $ingame_prefix
 * @property int $max_players
 * @property bool $planning_paused
 * @property bool $is_active
 * @property Carbon|null $last_seen_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'name', 'driver', 'base_url', 'credentials_ref', 'ingame_prefix', 'max_players', 'planning_paused', 'is_active', 'last_seen_at', 'meta'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => ServerDriverType::class,
            'max_players' => 'integer',
            'planning_paused' => 'boolean',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function capacityFor(?Track $track): int
    {
        return $track === null ? $this->max_players : min($this->max_players, $track->max_pit_slot);
    }

    /**
     * @return HasMany<Week, $this>
     */
    public function weeks(): HasMany
    {
        return $this->hasMany(Week::class);
    }

    /**
     * @return HasOne<ServerDefaultConfig, $this>
     */
    public function defaultConfig(): HasOne
    {
        return $this->hasOne(ServerDefaultConfig::class);
    }
}
