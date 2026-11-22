<?php

declare(strict_types=1);

namespace App\Integration\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocialHttp
{
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 30;

    private const ERROR_LIMIT = 200;

    public static function pending(): PendingRequest
    {
        return Http::connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT);
    }

    /**
     * @param  callable(): Response  $request
     */
    public static function send(callable $request, string $action): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new SocialChannelException("{$action}: connection error");
        }

        if ($response->failed()) {
            $body = Str::limit(trim((string) preg_replace('/\s+/', ' ', $response->body())), self::ERROR_LIMIT);

            throw new SocialChannelException("{$action}: HTTP {$response->status()} {$body}");
        }

        return $response;
    }
}
