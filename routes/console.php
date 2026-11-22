<?php

declare(strict_types=1);

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune')->daily();
Schedule::command('weeks:ensure')->everyMinute()->withoutOverlapping(5);
Schedule::command('social:prepare')->everyFiveMinutes()->timezone(config('mvp.timezone'))->withoutOverlapping(10);
Schedule::command('social:refresh-instagram-token')->weekly()->timezone(config('mvp.timezone'))->withoutOverlapping(10);
Schedule::command('servers:poll')->everyTenSeconds()->withoutOverlapping(2);
Schedule::command('servers:reconcile')->everyMinute()->withoutOverlapping(5);
Schedule::command('servers:ingest-logs')->everyThirtySeconds()->withoutOverlapping(2);
Schedule::command('donations:ensure-period')->monthlyOn(1, '00:00')->timezone(config('mvp.timezone'))->withoutOverlapping();
Schedule::command('catalog:sync')->daily()->timezone(config('mvp.timezone'))->withoutOverlapping();
Schedule::command('catalog:check-freshness')->hourly()->withoutOverlapping();
Schedule::command('seo:indexnow')->hourly()->withoutOverlapping();
Schedule::command('heartbeats:beat')->everyMinute();
Schedule::job(new RecordQueueHeartbeat)->everyMinute();
Schedule::command('heartbeats:check')->everyMinute()->withoutOverlapping(5);
