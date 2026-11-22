<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class IndexNowKeyController extends Controller
{
    public function __invoke(string $key): Response
    {
        $configured = config('services.indexnow.key');

        abort_unless(is_string($configured) && $configured !== '' && hash_equals($configured, $key), 404);

        return response($configured)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
