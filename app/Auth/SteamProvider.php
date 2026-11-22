<?php

declare(strict_types=1);

namespace App\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SocialiteProviders\Manager\OAuth2\User;
use SocialiteProviders\Steam\OpenIDValidationException;
use SocialiteProviders\Steam\Provider;

class SteamProvider extends Provider
{
    public const string StateKey = 'steam.state';

    private const string PlayerSummariesUrl = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/';

    private ?string $callbackUrl = null;

    private ?Client $steamHttpClient = null;

    public function redirect(): RedirectResponse
    {
        $state = Str::random(40);

        $this->request->session()->put(self::StateKey, $state);
        $this->redirectUrl = $this->callbackUrlWithState($state);

        return parent::redirect();
    }

    public function user()
    {
        $expected = $this->request->session()->pull(self::StateKey);
        $given = $this->request->query('state');

        if (! is_string($expected) || ! is_string($given) || ! hash_equals($expected, $given)) {
            throw new OpenIDValidationException('Invalid state');
        }

        $this->redirectUrl = $this->callbackUrlWithState($given);

        return parent::user();
    }

    public function parseSteamID(): void
    {
        $claimedId = Arr::get($this->request->all(), self::OPENID_CLAIMED_ID);

        if (! is_string($claimedId) || preg_match('#^https://steamcommunity\.com/openid/id/(\d{17})$#D', $claimedId, $matches) !== 1) {
            throw new OpenIDValidationException('Invalid openid.claimed_id');
        }

        $this->steamId = $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $profile = ['steamid' => (string) $token];

        if (empty($this->clientSecret)) {
            return $profile;
        }

        try {
            $response = $this->getHttpClient()->get(self::PlayerSummariesUrl, [
                'query' => ['key' => $this->clientSecret, 'steamids' => (string) $token],
            ]);
        } catch (GuzzleException) {
            return $profile;
        }

        $player = Arr::get(json_decode((string) $response->getBody(), true), 'response.players.0');

        if (! is_array($player) || ($player['steamid'] ?? null) !== (string) $token) {
            return $profile;
        }

        return $player;
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => (string) $user['steamid'],
            'nickname' => Arr::get($user, 'personaname'),
            'name' => Arr::get($user, 'realname'),
            'email' => null,
            'avatar' => Arr::get($user, 'avatarmedium'),
        ]);
    }

    protected function getHttpClient(): Client
    {
        return $this->steamHttpClient ??= Http::timeout(10)->buildClient();
    }

    private function callbackUrlWithState(string $state): string
    {
        $this->callbackUrl ??= (string) $this->redirectUrl;

        return $this->callbackUrl.'?'.http_build_query(['state' => $state], '', '&');
    }
}
