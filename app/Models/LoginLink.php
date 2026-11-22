<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginLinkPurpose;
use Database\Factories\LoginLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property LoginLinkPurpose $purpose
 * @property string $email
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $ip
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'purpose', 'email', 'token_hash', 'expires_at', 'ip'])]
class LoginLink extends Model
{
    /** @use HasFactory<LoginLinkFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => LoginLinkPurpose::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
