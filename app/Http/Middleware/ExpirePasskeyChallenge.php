<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class ExpirePasskeyChallenge
{
    private const array ChallengeKeys = [
        'passkey.verification_options',
        'passkey.registration_options',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();

        if ($request->isMethodSafe()) {
            $before = $this->challenges($session);
            $response = $next($request);

            foreach ($this->challenges($session) as $key => $challenge) {
                if ($challenge !== null && $challenge !== $before[$key]) {
                    $session->put($this->issuedAtKey($key), now()->getTimestamp());
                }
            }

            return $response;
        }

        foreach (self::ChallengeKeys as $key) {
            $issuedAt = $session->get($this->issuedAtKey($key));

            if (! is_int($issuedAt) || now()->getTimestamp() - $issuedAt > $this->lifetimeInSeconds()) {
                $session->forget([$key, $this->issuedAtKey($key)]);
            }
        }

        return $next($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function challenges(Session $session): array
    {
        return collect(self::ChallengeKeys)
            ->mapWithKeys(fn (string $key): array => [$key => $session->get($key)])
            ->all();
    }

    private function issuedAtKey(string $key): string
    {
        return $key.'_issued_at';
    }

    private function lifetimeInSeconds(): int
    {
        return intdiv(Config::integer('passkeys.timeout'), 1000);
    }
}
