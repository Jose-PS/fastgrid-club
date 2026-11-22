<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialChannelName;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $channel
 * @property bool $enabled
 * @property array<string, string> $credentials
 * @property Carbon|null $expires_at
 * @property Carbon|null $refreshed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['channel', 'enabled', 'credentials', 'expires_at', 'refreshed_at'])]
#[Hidden(['credentials'])]
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    public static function forChannel(SocialChannelName $channel): ?self
    {
        return self::query()->where('channel', $channel->value)->first();
    }

    public static function enabledFor(SocialChannelName $channel): ?self
    {
        $account = self::forChannel($channel);

        return $account !== null && $account->enabled ? $account : null;
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function hasCredentials(SocialChannelName $channel): bool
    {
        foreach ($channel->requiredFields() as $field) {
            if ($this->credential($field) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'expires_at' => 'datetime',
            'refreshed_at' => 'datetime',
        ];
    }
}
