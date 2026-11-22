<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Donations\DonationPayload;
use App\Support\Translations;
use Inertia\Inertia;
use Inertia\Response;

class DonateController extends Controller
{
    public function __invoke(Translations $translations, DonationPayload $payload): Response
    {
        $donation = $payload->get();

        abort_unless($donation['visible'], 404);

        return Inertia::render('donate', [
            'pageTranslations' => $translations->forGroups(['donate']),
            'donation' => $donation,
        ]);
    }
}
