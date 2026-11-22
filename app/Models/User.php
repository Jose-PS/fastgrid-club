<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IdentityProvider;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property string|null $locale
 * @property bool $is_admin
 * @property bool $show_in_rankings
 * @property string|null $remember_token
 * @property int $session_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $steam_id
 * @property-read UserIdentity|null $steamIdentity
 * @property-read UserIdentity|null $googleIdentity
 */
#[Fillable(['name', 'email', 'locale', 'show_in_rankings'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'session_version' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_admin' => 'boolean',
            'show_in_rankings' => 'boolean',
            'session_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<UserIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * @return HasOne<UserIdentity, $this>
     */
    public function googleIdentity(): HasOne
    {
        return $this->hasOne(UserIdentity::class)->where('provider', IdentityProvider::Google);
    }

    /**
     * @return HasOne<UserIdentity, $this>
     */
    public function steamIdentity(): HasOne
    {
        return $this->hasOne(UserIdentity::class)->where('provider', IdentityProvider::Steam);
    }

    public function hasSteam(): bool
    {
        return $this->steamIdentity !== null;
    }

    public function hasVotingIdentity(): bool
    {
        return $this->steamIdentity !== null || $this->googleIdentity !== null;
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function steamId(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->steamIdentity?->provider_id);
    }

    public function getPasskeyUsername(): string
    {
        return $this->email ?? $this->name;
    }
}
