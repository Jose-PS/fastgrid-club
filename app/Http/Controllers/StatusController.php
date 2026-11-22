<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PublicStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class StatusController extends Controller
{
    public function __invoke(PublicStatus $publicStatus): JsonResponse
    {
        $now = CarbonImmutable::now();
        $server = $publicStatus->activeServer();
        $week = $publicStatus->currentWeek($server, $now);

        return response()
            ->json($publicStatus->live($server, $week, $now))
            ->header('Cache-Control', 'public, max-age='.(int) config('mvp.status_cache_seconds'));
    }
}
