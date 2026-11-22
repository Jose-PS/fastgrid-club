<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthzController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            Redis::connection()->ping();
        } catch (Throwable $exception) {
            Log::error('Healthcheck failed', ['exception' => $exception]);

            return response()->json(['status' => 'error'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
