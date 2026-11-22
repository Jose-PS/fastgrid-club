<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $server_id
 * @property AlertType $type
 * @property AlertStatus $status
 * @property string $summary
 * @property string|null $summary_key
 * @property array<string, scalar>|null $summary_params
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server|null $server
 */
#[Fillable(['server_id', 'type', 'status', 'summary', 'summary_key', 'summary_params', 'subject_type', 'subject_id', 'opened_at', 'closed_at'])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'status' => AlertStatus::class,
            'summary_params' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function localizedSummary(): string
    {
        if ($this->summary_key === null) {
            return $this->summary;
        }

        return __($this->summary_key, $this->summary_params ?? []);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<Alert>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', AlertStatus::Open);
    }
}
