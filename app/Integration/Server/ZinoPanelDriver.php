<?php

declare(strict_types=1);

namespace App\Integration\Server;

use App\Enums\InitialGrip;
use App\Enums\RaceDurationType;
use App\Enums\ServerType;
use App\Enums\TrackMode;
use App\Enums\TuningType;
use App\Enums\WeatherBehaviour;
use App\Enums\WeatherType;
use App\Integration\Server\Data\Catalog;
use App\Integration\Server\Data\ConfigPlan;
use App\Integration\Server\Data\ConfigSnapshot;
use App\Integration\Server\Data\EditableConfig;
use App\Integration\Server\Data\LiveDriver;
use App\Integration\Server\Data\LiveSnapshot;
use App\Integration\Server\Data\LogEvent;
use App\Integration\Server\Data\LogEvents;
use App\Integration\Server\Data\LogTail;
use App\Integration\Server\Data\MandatoryPitstop;
use App\Integration\Server\Data\OperationResult;
use App\Integration\Server\Data\ServerSecrets;
use App\Integration\Server\Data\ServerStatusReport;
use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class ZinoPanelDriver implements ServerDriver
{
    private const CONNECT_TIMEOUT = 3;

    private const READ_TIMEOUT = 8;

    private const APPLY_TIMEOUT = 30;

    private const RETRY_DELAYS_MS = [200, 400];

    private const PANEL_TEXT_LIMIT = 300;

    private const EVENT_TYPES = [
        'practice' => 'GameModeType_PRACTICE',
        'race_weekend' => 'GameModeType_RACE_WEEKEND',
    ];

    private const DURATION_TYPES = [
        'time' => 'GameModeSelectionDuration_TIME',
        'laps' => 'GameModeSelectionDuration_LAPS',
    ];

    private const WEATHER_TYPES = [
        'clear' => 'GameModeSelectionWeatherType_CLEAR',
        'scattered_clouds' => 'GameModeSelectionWeatherType_SCATTERED_CLOUDS',
        'broken_clouds' => 'GameModeSelectionWeatherType_BROKEN_CLOUDS',
        'overcast' => 'GameModeSelectionWeatherType_OVERCAST',
        'drizzle' => 'GameModeSelectionWeatherType_DRIZZLE',
        'rain' => 'GameModeSelectionWeatherType_RAIN',
        'heavy_rain' => 'GameModeSelectionWeatherType_HEAVY_RAIN',
        'damp' => 'GameModeSelectionWeatherType_DAMP',
    ];

    private const DEFAULT_MAX_WAIT_TO_BOX = 10;

    private const DEFAULT_OVERTIME_WAITING = 10;

    private const DEFAULT_WARMUP_SECONDS = 0;

    private const DEFAULT_MIN_WAITING = 10;

    private const DEFAULT_MAX_WAITING = 60;

    private const DEFAULT_PITSTOP_WINDOW = 600;

    private const WEATHER_BEHAVIOURS = [
        'static' => 'GameModeSelectionWeatherBehaviour_STATIC',
        'dynamic' => 'GameModeSelectionWeatherBehaviour_DYNAMIC',
    ];

    private const SERVER_TYPES = [
        'ranked' => 'MultiplayerServerListSessionType_RANKED',
        'unranked' => 'MultiplayerServerListSessionType_UNRANKED',
    ];

    private const TUNING_TYPES = [
        'allowed' => 'TuningAllowed',
        'denied' => 'TuningDenied',
    ];

    private const INITIAL_GRIPS = [
        'green' => 'InitialGrip_GREEN',
        'fast' => 'InitialGrip_FAST',
        'optimum' => 'InitialGrip_OPTIMUM',
    ];

    private const SECRET_KEY_PATTERN = '/pass|secret|token/i';

    private const REDACTED = '[redacted]';

    /**
     * @var list<string>
     */
    private array $formSecrets = [];

    public function __construct(
        private readonly Server $server,
        private readonly string $baseUrl,
        private readonly ?string $user,
        private readonly ?string $password,
        private readonly ServerCircuitBreaker $breaker,
    ) {}

    public function status(): ServerStatusReport
    {
        $data = $this->read('status', '/api/server/status');

        return new ServerStatusReport(
            (bool) ($data['running'] ?? false),
            (string) ($data['state'] ?? 'unknown'),
            isset($data['last_exit_code']) ? (int) $data['last_exit_code'] : null,
        );
    }

    public function live(): LiveSnapshot
    {
        $data = $this->read('live', '/api/server/live');

        $drivers = array_map(
            fn (array $driver): LiveDriver => new LiveDriver(
                (string) ($driver['car'] ?? ''),
                (string) ($driver['name'] ?? ''),
                isset($driver['number']) ? (int) $driver['number'] : null,
                (int) ($driver['laps'] ?? 0),
                isset($driver['last_lap_ms']) ? (int) $driver['last_lap_ms'] : null,
                isset($driver['best_lap_ms']) ? (int) $driver['best_lap_ms'] : null,
            ),
            array_values(array_filter((array) ($data['drivers'] ?? []), 'is_array')),
        );

        return new LiveSnapshot((bool) ($data['running'] ?? false), (int) ($data['players'] ?? 0), $drivers);
    }

    public function logTail(int $lines): LogTail
    {
        $data = $this->read('logs', '/api/server/logs', ['tail' => max(1, $lines)]);

        $text = (string) ($data['lines'] ?? '');

        if ($text === '') {
            return new LogTail([]);
        }

        $all = explode("\n", $text);

        if (end($all) === '') {
            array_pop($all);
        }

        return new LogTail($all);
    }

    public function logEvents(int $afterId, int $limit): LogEvents
    {
        $data = $this->read('events', '/api/server/events', ['after' => max(0, $afterId), 'limit' => min(5000, max(1, $limit))]);

        $events = [];

        foreach ((array) ($data['events'] ?? []) as $event) {
            if (is_array($event) && isset($event['id'], $event['line'])) {
                $events[] = new LogEvent((int) $event['id'], (string) $event['line']);
            }
        }

        return new LogEvents(
            $events,
            isset($data['first_id']) ? (int) $data['first_id'] : null,
            isset($data['last_id']) ? (int) $data['last_id'] : null,
        );
    }

    public function catalog(): Catalog
    {
        return ZinoMetadata::toCatalog($this->read('catalog', '/api/metadata'));
    }

    public function currentConfig(): ConfigSnapshot
    {
        $form = $this->read('config', '/api/config')['form'] ?? null;

        if (! is_array($form)) {
            throw $this->failure('config', 'invalid response');
        }

        $eventType = $form['event']['type'] ?? null;
        $practiceSeconds = $form['sessions']['practice']['length_sec'] ?? null;
        $durationType = $form['sessions']['race']['duration_type'] ?? null;

        $selected = array_values(array_map(
            fn (array $car): string => (string) $car['name'],
            array_filter((array) ($form['cars'] ?? []), fn (mixed $car): bool => is_array($car) && ($car['is_selected'] ?? false) === true),
        ));

        return new ConfigSnapshot(
            $eventType === null ? null : (TrackMode::tryFrom((string) array_search($eventType, self::EVENT_TYPES, true))),
            isset($form['event']['track']) ? (string) $form['event']['track'] : null,
            $selected,
            isset($form['server']['server_name']) ? (string) $form['server']['server_name'] : null,
            isset($form['server']['max_players']) ? (int) $form['server']['max_players'] : null,
            isset($form['sessions']['qualify']['length_sec']) ? (int) $form['sessions']['qualify']['length_sec'] : null,
            isset($form['sessions']['race']['length_sec']) ? (int) $form['sessions']['race']['length_sec'] : null,
            $practiceSeconds === null || (int) $practiceSeconds === 0 ? null : (int) $practiceSeconds,
            isset($form['server']['cycle_enabled']) ? (bool) $form['server']['cycle_enabled'] : null,
            $durationType === null ? null : RaceDurationType::tryFrom((string) array_search($durationType, self::DURATION_TYPES, true)),
            isset($form['sessions']['race']['laps']) ? (int) $form['sessions']['race']['laps'] : null,
        );
    }

    public function editableConfig(): EditableConfig
    {
        $form = $this->read('config', '/api/config')['form'] ?? null;

        if (! is_array($form)) {
            throw $this->failure('config', 'invalid response');
        }

        $server = (array) ($form['server'] ?? []);
        $event = (array) ($form['event'] ?? []);
        $sessions = (array) ($form['sessions'] ?? []);

        $selected = array_values(array_map(
            fn (array $car): string => (string) $car['name'],
            array_filter((array) ($form['cars'] ?? []), fn (mixed $car): bool => is_array($car) && ($car['is_selected'] ?? false) === true),
        ));

        $mapped = [];

        foreach (ConfigPlan::SESSIONS as $name) {
            $mapped[$name] = $this->editableSession((array) ($sessions[$name] ?? []), $name === 'race');
        }

        return new EditableConfig(
            isset($server['server_name']) ? (string) $server['server_name'] : null,
            isset($server['max_players']) ? (int) $server['max_players'] : null,
            isset($server['cycle_enabled']) ? (bool) $server['cycle_enabled'] : null,
            $this->enumFrom(ServerType::class, self::SERVER_TYPES, $server['server_type'] ?? null),
            $this->enumFrom(TuningType::class, self::TUNING_TYPES, $server['tuning_type'] ?? null),
            isset($server['driver_password']) ? (string) $server['driver_password'] : null,
            isset($server['admin_password']) ? (string) $server['admin_password'] : null,
            isset($server['spectator_password']) ? (string) $server['spectator_password'] : null,
            $this->enumFrom(TrackMode::class, self::EVENT_TYPES, $event['type'] ?? null),
            isset($event['track']) ? (string) $event['track'] : null,
            $this->enumFrom(WeatherType::class, self::WEATHER_TYPES, $event['weather'] ?? null),
            $this->enumFrom(WeatherBehaviour::class, self::WEATHER_BEHAVIOURS, $event['weather_behaviour'] ?? null),
            $this->enumFrom(InitialGrip::class, self::INITIAL_GRIPS, $event['initial_grip'] ?? null),
            $selected,
            $mapped,
        );
    }

    public function apply(ConfigPlan $plan, ?ServerSecrets $secrets = null): OperationResult
    {
        try {
            $form = $this->read('apply', '/api/config')['form'] ?? null;

            if (! is_array($form)) {
                throw $this->failure('apply', 'invalid response');
            }

            $this->formSecrets = [
                ...$this->collectSecrets($form),
                ...array_filter([$secrets?->driverPassword, $secrets?->adminPassword, $secrets?->spectatorPassword], fn (?string $value): bool => $value !== null && $value !== ''),
            ];

            $response = $this->send('apply', 'POST', '/api/server/apply', [], ['form' => $this->applyPlan($form, $plan, $secrets)], false, self::APPLY_TIMEOUT);
        } catch (ServerDriverException $exception) {
            return new OperationResult(false, $exception->getMessage());
        }

        return $this->operationResult('apply', 'applied', $response);
    }

    public function start(): OperationResult
    {
        try {
            $response = $this->send('start', 'POST', '/api/server/start', [], [], false, self::READ_TIMEOUT);
        } catch (ServerDriverException $exception) {
            return new OperationResult(false, $exception->getMessage());
        }

        return $this->operationResult('start', 'started', $response);
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function applyPlan(array $form, ConfigPlan $plan, ?ServerSecrets $secrets): array
    {
        $form['server']['server_name'] = $plan->serverName;
        $form['server']['max_players'] = $plan->maxPlayers;
        $form['server']['cycle_enabled'] = $plan->cycleEnabled;

        $form['event']['type'] = self::EVENT_TYPES[$plan->mode->value];
        $form['event']['track'] = $plan->trackRef;
        $form['event']['weather'] = self::WEATHER_TYPES[$plan->weather->value];
        $form['event']['weather_behaviour'] = self::WEATHER_BEHAVIOURS[($plan->weatherBehaviour ?? WeatherBehaviour::Static)->value];

        $form['event']['initial_grip'] = self::INITIAL_GRIPS[($plan->initialGrip ?? InitialGrip::Optimum)->value];

        if ($plan->serverType !== null) {
            $form['server']['server_type'] = self::SERVER_TYPES[$plan->serverType->value];
        }

        if ($plan->tuningType !== null) {
            $form['server']['tuning_type'] = self::TUNING_TYPES[$plan->tuningType->value];
        }

        if ($secrets !== null) {
            $form['server'] = $this->applySecrets($form['server'], $secrets);
        }

        foreach (ConfigPlan::SESSIONS as $session) {
            $settings = array_key_exists($session, $plan->sessionSettings) ? $plan->sessionSettings[$session] : null;

            $form['sessions'][$session]['hour'] = $settings->hour ?? $plan->startHour;
            $form['sessions'][$session]['minute'] = $settings->minute ?? 0;
            $form['sessions'][$session]['time_multiplier'] = $settings->timeMultiplier ?? 1;

            $form['sessions'][$session]['max_wait_to_box'] = $settings->maxWaitToBox ?? self::DEFAULT_MAX_WAIT_TO_BOX;
            $form['sessions'][$session]['overtime_waiting_next_session'] = $settings->overtimeWaitingNextSession ?? self::DEFAULT_OVERTIME_WAITING;
        }

        $form['cars'] = array_map(
            function (mixed $car) use ($plan): mixed {
                if (is_array($car)) {
                    $car['is_selected'] = in_array($car['name'] ?? null, $plan->carRefs, true);
                }

                return $car;
            },
            (array) ($form['cars'] ?? []),
        );

        $form['sessions']['qualify']['length_sec'] = $plan->qualifyingSeconds;
        $form['sessions']['race']['duration_type'] = self::DURATION_TYPES[$plan->raceDurationType->value];

        if ($plan->raceDurationType === RaceDurationType::Laps) {
            $form['sessions']['race']['laps'] = $plan->raceLaps;
        } elseif ($plan->raceSeconds !== null) {
            $form['sessions']['race']['length_sec'] = $plan->raceSeconds;
        }

        if ($plan->practiceSeconds !== null) {
            $form['sessions']['practice']['length_sec'] = $plan->practiceSeconds;
        }

        $form['sessions']['warmup']['length_sec'] = $plan->warmupSeconds ?? self::DEFAULT_WARMUP_SECONDS;
        $form['sessions']['race']['min_waiting_for_players'] = $plan->minWaitingForPlayers ?? self::DEFAULT_MIN_WAITING;
        $form['sessions']['race']['max_waiting_for_players'] = $plan->maxWaitingForPlayers ?? self::DEFAULT_MAX_WAITING;

        $pitstop = $plan->mandatoryPitstop ?? new MandatoryPitstop(false, self::DEFAULT_PITSTOP_WINDOW, true, true);
        $form['sessions']['race']['mandatory_pitstop_enabled'] = $pitstop->enabled;
        $form['sessions']['race']['mandatory_pitstop_window_seconds'] = $pitstop->windowSeconds;
        $form['sessions']['race']['mandatory_pitstop_refuel'] = $pitstop->refuel;
        $form['sessions']['race']['mandatory_pitstop_tyre_change'] = $pitstop->tyreChange;

        return $form;
    }

    /**
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    private function applySecrets(array $server, #[\SensitiveParameter] ServerSecrets $secrets): array
    {
        if ($secrets->driverPassword !== null) {
            $server['driver_password'] = $secrets->driverPassword;
        }

        if ($secrets->adminPassword !== null) {
            $server['admin_password'] = $secrets->adminPassword;
        }

        if ($secrets->spectatorPassword !== null) {
            $server['spectator_password'] = $secrets->spectatorPassword;
        }

        return $server;
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function editableSession(array $session, bool $race): array
    {
        $data = [
            'length_sec' => isset($session['length_sec']) ? (int) $session['length_sec'] : null,
            'hour' => isset($session['hour']) ? (int) $session['hour'] : null,
            'minute' => isset($session['minute']) ? (int) $session['minute'] : null,
            'time_multiplier' => isset($session['time_multiplier']) ? (int) $session['time_multiplier'] : null,
            'max_wait_to_box' => isset($session['max_wait_to_box']) ? (int) $session['max_wait_to_box'] : null,
            'overtime_waiting_next_session' => isset($session['overtime_waiting_next_session']) ? (int) $session['overtime_waiting_next_session'] : null,
        ];

        if (! $race) {
            return $data;
        }

        return $data + [
            'duration_type' => $this->enumFrom(RaceDurationType::class, self::DURATION_TYPES, $session['duration_type'] ?? null)?->value,
            'laps' => isset($session['laps']) ? (int) $session['laps'] : null,
            'min_waiting_for_players' => isset($session['min_waiting_for_players']) ? (int) $session['min_waiting_for_players'] : null,
            'max_waiting_for_players' => isset($session['max_waiting_for_players']) ? (int) $session['max_waiting_for_players'] : null,
            'mandatory_pitstop_enabled' => isset($session['mandatory_pitstop_enabled']) ? (bool) $session['mandatory_pitstop_enabled'] : null,
            'mandatory_pitstop_window_seconds' => isset($session['mandatory_pitstop_window_seconds']) ? (int) $session['mandatory_pitstop_window_seconds'] : null,
            'mandatory_pitstop_refuel' => isset($session['mandatory_pitstop_refuel']) ? (bool) $session['mandatory_pitstop_refuel'] : null,
            'mandatory_pitstop_tyre_change' => isset($session['mandatory_pitstop_tyre_change']) ? (bool) $session['mandatory_pitstop_tyre_change'] : null,
        ];
    }

    /**
     * @template TEnum of \BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @param  array<string, string>  $map
     * @return TEnum|null
     */
    private function enumFrom(string $enum, array $map, mixed $panelValue): ?\BackedEnum
    {
        if (! is_string($panelValue)) {
            return null;
        }

        $key = array_search($panelValue, $map, true);

        return $key === false ? null : $enum::tryFrom((string) $key);
    }

    private function operationResult(string $operation, string $okMessage, Response $response): OperationResult
    {
        if ($response->json('ok') === true) {
            return new OperationResult(true, $okMessage);
        }

        $reason = $this->panelReason($response) ?? 'rejected by panel';

        return new OperationResult(false, "zino_panel {$operation}: {$reason}");
    }

    private function panelReason(Response $response): ?string
    {
        $error = $this->panelError($response);

        if ($error !== null) {
            return $error;
        }

        $warnings = array_filter(
            (array) $response->json('warnings'),
            fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '',
        );

        return $warnings === [] ? null : $this->panelText(implode('; ', $warnings));
    }

    private function panelError(Response $response): ?string
    {
        $error = $response->json('error');

        if (! is_string($error) || trim($error) === '') {
            return null;
        }

        return $this->panelText(trim($error));
    }

    private function panelText(string $text): string
    {
        $secrets = array_filter(
            [$this->user, $this->password, ...$this->formSecrets],
            fn (?string $secret): bool => $secret !== null && $secret !== '',
        );

        usort($secrets, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return mb_substr(str_replace($secrets, self::REDACTED, $text), 0, self::PANEL_TEXT_LIMIT);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function collectSecrets(array $data): array
    {
        $secrets = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                array_push($secrets, ...$this->collectSecrets($value));
            } elseif (is_string($value) && $value !== '' && is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                $secrets[] = $value;
            }
        }

        return $secrets;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function read(string $operation, string $path, array $query = []): array
    {
        $data = $this->send($operation, 'GET', $path, $query, [], true, self::READ_TIMEOUT)->json();

        if (! is_array($data)) {
            $this->breaker->recordFailure($this->server);

            throw $this->failure($operation, 'invalid response');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    private function send(string $operation, string $method, string $path, array $query, array $body, bool $retry, int $timeout): Response
    {
        try {
            $response = $this->pending($retry, $timeout)->send($method, $path, $method === 'GET' ? ['query' => $query] : ['json' => $body]);
        } catch (ConnectionException $exception) {
            $this->breaker->recordFailure($this->server);

            throw $this->failure($operation, $this->isTimeout($exception) ? 'timeout' : 'connection failed');
        } catch (RequestException $exception) {
            $this->breaker->recordFailure($this->server);

            throw $this->failure($operation, 'HTTP '.$exception->response->status());
        }

        if ($response->failed()) {
            $this->breaker->recordFailure($this->server);

            $error = $this->panelError($response);

            throw $this->failure($operation, 'HTTP '.$response->status().($error === null ? '' : ": {$error}"));
        }

        $this->breaker->recordSuccess($this->server);

        return $response;
    }

    private function pending(bool $retry, int $timeout): PendingRequest
    {
        $pending = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout($timeout);

        if ($this->user !== null && $this->user !== '') {
            $pending = $pending->withBasicAuth($this->user, (string) $this->password);
        }

        if ($retry) {
            $pending = $pending->retry(
                self::RETRY_DELAYS_MS,
                when: fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
            );
        }

        return $pending;
    }

    private function isTimeout(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out') || str_contains($message, 'error 28');
    }

    private function failure(string $operation, string $reason): ServerDriverException
    {
        return new ServerDriverException("zino_panel {$operation}: {$reason}");
    }
}
