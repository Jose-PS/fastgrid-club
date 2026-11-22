<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Auth\RecentConfirmation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentConfirmation
{
    public function __construct(private readonly RecentConfirmation $recentConfirmation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->recentConfirmation->isFresh($request->session())) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json(['message' => __('account.confirm.required')], 423);
        }

        return redirect()->route('account.confirm.show');
    }
}
