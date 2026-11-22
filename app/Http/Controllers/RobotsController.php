<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class RobotsController extends Controller
{
    private const DISALLOWED = ['/admin', '/account', '/user', '/api', '/login/link'];

    public function __invoke(): Response
    {
        $lines = ['User-agent: *'];

        foreach (self::DISALLOWED as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.rtrim(config()->string('app.url'), '/').'/sitemap.xml';

        return response(implode("\n", $lines)."\n")
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
