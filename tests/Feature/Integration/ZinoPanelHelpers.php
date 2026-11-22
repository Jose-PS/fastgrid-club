<?php

declare(strict_types=1);

use App\Enums\ServerDriverType;
use App\Integration\Server\ServerDriverManager;
use App\Integration\Server\ZinoPanelDriver;
use App\Models\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const ZINO_URL = 'http://panel.test:8090';
const ZINO_USER = 'panel-user';
const ZINO_PASS = 'panel-pass-xyz';

/**
 * @return array<string, mixed>
 */
function zinoFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/zino/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
}

function zinoServer(): Server
{
    config(['servers.main' => ['url' => ZINO_URL, 'user' => ZINO_USER, 'password' => ZINO_PASS]]);

    return Server::factory()->create(['driver' => ServerDriverType::ZinoPanel, 'credentials_ref' => 'main']);
}

function zinoDriver(?Server $server = null): ZinoPanelDriver
{
    $driver = app(ServerDriverManager::class)->for($server ?? zinoServer());

    assert($driver instanceof ZinoPanelDriver);

    return $driver;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function zinoFake(array $overrides = []): void
{
    Sleep::fake();
    Http::preventStrayRequests();

    $form = zinoFixture('config')['form'];

    Http::fake(function (Request $request) use (&$form, $overrides) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $key = $request->method().' '.$path;

        if (array_key_exists($key, $overrides)) {
            $override = $overrides[$key];

            return is_callable($override) ? $override($request) : $override;
        }

        return match ($key) {
            'GET /api/server/status' => Http::response(zinoFixture('status')),
            'GET /api/server/live' => Http::response(zinoFixture('live')),
            'GET /api/server/logs' => Http::response(zinoLogs($request)),
            'GET /api/server/events' => Http::response(zinoEvents($request)),
            'GET /api/metadata' => Http::response(zinoFixture('metadata')),
            'GET /api/config' => Http::response(['form' => $form]),
            'POST /api/server/apply' => tap(Http::response(['ok' => true, 'restarted' => false]), function () use (&$form, $request): void {
                $form = $request->data()['form'];
            }),
            'POST /api/server/start' => Http::response(['ok' => true, 'running' => true]),
            default => Http::response(['error' => 'not found'], 404),
        };
    });
}

/**
 * @return array<string, mixed>
 */
function zinoLogs(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    $logs = zinoFixture('logs');
    $lines = explode("\n", (string) $logs['lines']);
    $logs['lines'] = implode("\n", array_slice($lines, -max(1, (int) ($query['tail'] ?? 200))));

    return $logs;
}

/**
 * @return array<string, mixed>
 */
function zinoEvents(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    $lines = explode("\n", trim((string) zinoFixture('logs')['lines']));
    $events = [];

    foreach ($lines as $index => $line) {
        if ($index + 1 > (int) ($query['after'] ?? 0) && count($events) < (int) ($query['limit'] ?? 1000)) {
            $events[] = ['id' => $index + 1, 'line' => $line];
        }
    }

    return ['events' => $events, 'first_id' => 1, 'last_id' => count($lines)];
}
