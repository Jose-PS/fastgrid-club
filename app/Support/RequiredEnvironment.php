<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Env;

class RequiredEnvironment
{
    public const REQUIRED = [
        'APP_KEY',
        'APP_URL',
        'DB_HOST',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'REDIS_HOST',
        'REDIS_PASSWORD',
        'SESSION_DRIVER',
        'CACHE_STORE',
        'QUEUE_CONNECTION',
        'MAIL_HOST',
        'MAIL_FROM_ADDRESS',
        'GOOGLE_CLIENT_ID',
        'GOOGLE_CLIENT_SECRET',
        'SERVER_URL',
        'SERVER_USER',
        'SERVER_PASS',
        'TRUSTED_PROXIES',
    ];

    public const MAIL_SCHEMES = ['smtp', 'smtps'];

    private readonly Closure $reader;

    public function __construct(?Closure $reader = null)
    {
        $this->reader = $reader ?? fn (string $name): mixed => Env::get($name);
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            self::REQUIRED,
            fn (string $name): bool => ! $this->isPresent($name),
        ));
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = array_map(
            fn (string $name): string => "Falta la variable de entorno obligatoria {$name}.",
            $this->missing(),
        );

        if (($this->reader)('APP_DEBUG') === true) {
            $problems[] = 'APP_DEBUG debe ser false en producción.';
        }

        if (($this->reader)('APP_ENV') !== 'production') {
            $problems[] = 'APP_ENV debe ser production.';
        }

        $mailScheme = ($this->reader)('MAIL_SCHEME');

        if ($mailScheme !== null && $mailScheme !== '' && ! in_array($mailScheme, self::MAIL_SCHEMES, true)) {
            $problems[] = 'MAIL_SCHEME solo admite smtp o smtps, no «'.$mailScheme.'».';
        }

        return $problems;
    }

    private function isPresent(string $name): bool
    {
        $value = ($this->reader)($name);

        return $value !== null && $value !== '';
    }
}
