<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        $cspHeader = app()->environment('staging')
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($cspHeader, $this->contentSecurityPolicy());
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', $this->permissionsPolicy());
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $nonce = Vite::cspNonce();
        $scriptSrc = ["'self'", "'nonce-{$nonce}'"];
        $styleSrc = ["'self'"];
        $connectSrc = ["'self'"];

        $devServer = $this->devServerOrigins();

        if ($devServer !== null) {
            $scriptSrc[] = $devServer['http'];
            $styleSrc[] = $devServer['http'];
            $styleSrc[] = "'unsafe-inline'";
            $connectSrc[] = $devServer['http'];
            $connectSrc[] = $devServer['ws'];
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSrc),
            'style-src '.implode(' ', $styleSrc),
            "img-src 'self' data:",
            'connect-src '.implode(' ', $connectSrc),
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }

    /**
     * @return array{http: string, ws: string}|null
     */
    private function devServerOrigins(): ?array
    {
        if (! app()->environment('local') || ! Vite::isRunningHot()) {
            return null;
        }

        $hotFile = Vite::hotFile();
        $url = is_file($hotFile) ? trim((string) file_get_contents($hotFile)) : '';
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $authority = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $webSocketScheme = $parts['scheme'] === 'https' ? 'wss' : 'ws';

        return [
            'http' => $parts['scheme'].'://'.$authority,
            'ws' => $webSocketScheme.'://'.$authority,
        ];
    }

    private function permissionsPolicy(): string
    {
        return implode(', ', [
            'accelerometer=()',
            'autoplay=()',
            'camera=()',
            'display-capture=()',
            'geolocation=()',
            'gyroscope=()',
            'magnetometer=()',
            'microphone=()',
            'payment=()',
            'usb=()',
            'publickey-credentials-get=(self)',
            'publickey-credentials-create=(self)',
        ]);
    }
}
