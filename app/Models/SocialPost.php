<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialPostStatus;
use App\Enums\SocialPostType;
use Database\Factories\SocialPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property SocialPostType $type
 * @property string $key
 * @property int|null $server_id
 * @property int|null $week_id
 * @property SocialPostStatus $status
 * @property string $text
 * @property array<string, mixed> $data
 * @property string|null $image_path
 * @property array<string, mixed>|null $deliveries
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server|null $server
 * @property-read Week|null $week
 * @property-read User|null $approver
 */
#[Fillable(['type', 'key', 'server_id', 'week_id', 'status', 'text', 'data', 'image_path', 'deliveries', 'approved_by', 'approved_at', 'published_at'])]
class SocialPost extends Model
{
    /** @use HasFactory<SocialPostFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SocialPostType::class,
            'status' => SocialPostStatus::class,
            'data' => 'array',
            'deliveries' => 'array',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
