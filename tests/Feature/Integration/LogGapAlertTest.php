<?php

declare(strict_types=1);

use App\Actions\Servers\IngestServerLogs;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Integration\Server\ServerDriver;
use App\Integration\Server\ServerDriverManager;
use App\Models\Alert;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/ScriptedEventDriver.php';

final class GapAlertDriverManager extends ServerDriverManager
{
    public function for(Server $server): ServerDriver
    {
        return new ScriptedEventDriver;
    }
}

function gapRead(Server $server, array $lines, int $offset = 0): void
{
    ScriptedEventDriver::$lines = $lines;
    ScriptedEventDriver::$offset = $offset;
    Cache::forget(IngestServerLogs::lastAttemptKey($server));

    app(IngestServerLogs::class)->handle($server);
}

function numberedLines(string $prefix, int $count): array
{
    return array_map(fn (int $i): string => "{$prefix} line {$i}", range(1, $count));
}

beforeEach(function () {
    Cache::flush();
    ScriptedEventDriver::reset();
    app()->instance(ServerDriverManager::class, new GapAlertDriverManager);
    $this->server = Server::factory()->create(['is_active' => true, 'name' => 'Main server']);
});

test('a log gap opens a persistent alert and it only closes after a day without gaps', function () {
    gapRead($this->server, numberedLines('first', 60));
    expect(Alert::query()->count())->toBe(0);

    gapRead($this->server, numberedLines('second', 60), 100);

    $alert = Alert::query()->sole();
    expect($alert->type)->toBe(AlertType::LogGap)
        ->and($alert->status)->toBe(AlertStatus::Open)
        ->and($alert->server_id)->toBe($this->server->id)
        ->and($alert->summary_key)->toBe('admin.alerts.logGap');

    $overlapping = [...numberedLines('second', 60), ...numberedLines('third', 5)];
    gapRead($this->server, $overlapping, 100);
    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Open);

    $this->travelTo(now()->addHours(25));
    gapRead($this->server, [...$overlapping, ...numberedLines('fourth', 5)], 100);

    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Closed);
});

test('a second gap does not duplicate the open alert', function () {
    gapRead($this->server, numberedLines('first', 60));
    gapRead($this->server, numberedLines('second', 60), 100);
    gapRead($this->server, numberedLines('third', 60), 200);

    expect(Alert::query()->where('type', AlertType::LogGap)->count())->toBe(1);
});
