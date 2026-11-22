<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Models\ServerSession;

interface ResultsSource
{
    public function rebuild(ServerSession $session): void;
}
