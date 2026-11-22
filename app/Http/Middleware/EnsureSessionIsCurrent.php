<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Auth\SessionVersion;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionIsCurrent
{
    public function __construct(private readonly SessionVersion $sessionVersion) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $guard->user();

        if ($user instanceof User && ! $this->sessionVersion->isCurrent($request->session(), $user)) {
            $guard->logoutCurrentDevice();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
