<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\SitemapEntries;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(SitemapEntries $sitemapEntries): Response
    {
        return response()
            ->view('sitemap', ['entries' => $sitemapEntries->all()])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
