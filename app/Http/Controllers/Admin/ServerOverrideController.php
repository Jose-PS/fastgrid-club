<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Servers\ApplyServerOverride;
use App\Actions\Servers\EndServerOverride;
use App\Actions\Servers\ServerOverrideResult;
use App\Enums\ConfigApplicationStatus;
use App\Enums\InitialGrip;
use App\Enums\RaceDurationType;
use App\Enums\ReconcileResult;
use App\Enums\ServerType;
use App\Enums\TrackMode;
use App\Enums\TuningType;
use App\Enums\WeatherBehaviour;
use App\Enums\WeatherType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServerOverrideRequest;
use App\Http\Resources\CatalogCarResource;
use App\Integration\Server\Data\EditableConfig;
use App\Integration\Server\ServerDriverManager;
use App\Models\Car;
use App\Models\ConfigApplication;
use App\Models\Server;
use App\Models\ServerOverride;
use App\Models\Track;
use App\Models\User;
use App\Support\AdminServerStatus;
use App\Support\Translations;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ServerOverrideController extends Controller
{
    public function show(Translations $translations, ServerDriverManager $drivers, AdminServerStatus $status): Response
    {
        $server = $this->activeServer();
        $panel = $this->panelConfig($drivers, $server);

        return Inertia::render('admin/server-override', [
            'pageTranslations' => $translations->forGroups(['admin', 'props']),
            'server' => ['id' => $server->id, 'name' => $server->name, 'max_players' => $server->max_players, 'planning_paused' => $server->planning_paused],
            'config' => $panel['config'],
            'configError' => $panel['error'],
            'override' => $this->activeOverride($server),
            'players' => $status->live($server)['players'],
            'options' => $this->options(),
        ]);
    }

    public function store(StoreServerOverrideRequest $request, ApplyServerOverride $applyServerOverride): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $result = $applyServerOverride->handle($user, $this->activeServer(), $request->overrideInput());

        Inertia::flash('toast', [
            'type' => match ($result->status) {
                ServerOverrideResult::APPLIED => 'success',
                ServerOverrideResult::FAILED => 'error',
                default => 'warning',
            },
            'message' => match ($result->status) {
                ServerOverrideResult::APPLIED => __('admin.override.applied'),
                ServerOverrideResult::FAILED => __('admin.override.failed', ['error' => (string) $result->error]),
                default => __('admin.override.busy'),
            },
        ]);

        return to_route('admin.override.show');
    }

    public function destroy(Request $request, EndServerOverride $endServerOverride): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $server = $this->activeServer();
        $result = $endServerOverride->handle($user, $server);

        Inertia::flash('toast', match ($result) {
            null => ['type' => 'warning', 'message' => __('admin.override.noneActive')],
            ReconcileResult::Paused => ['type' => 'warning', 'message' => __('admin.override.endedPaused')],
            ReconcileResult::Busy => ['type' => 'warning', 'message' => __('admin.override.endedBusy')],
            ReconcileResult::NoConfig => ['type' => 'warning', 'message' => __('admin.override.endedNoConfig')],
            ReconcileResult::Failed => ['type' => 'error', 'message' => __('admin.override.endedFailed', ['error' => $this->lastError($server)])],
            default => ['type' => 'success', 'message' => __('admin.override.ended')],
        });

        return to_route('admin.override.show');
    }

    private function lastError(Server $server): string
    {
        return (string) ConfigApplication::query()
            ->where('server_id', $server->id)
            ->where('status', ConfigApplicationStatus::Failed)
            ->orderByDesc('id')
            ->value('error');
    }

    private function activeServer(): Server
    {
        return Server::query()->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    /**
     * @return array{config: array<string, mixed>|null, error: string|null}
     */
    private function panelConfig(ServerDriverManager $drivers, Server $server): array
    {
        try {
            $editable = $drivers->for($server)->editableConfig();
        } catch (Throwable) {
            return ['config' => null, 'error' => __('admin.override.panelUnavailable')];
        }

        return ['config' => $this->formFrom($editable), 'error' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function formFrom(EditableConfig $config): array
    {
        $cars = Car::query()->whereIn('internal_name', $config->carRefs)->where('active', true)->pluck('id')->sort()->values()->all();

        return [
            'server_name' => $config->serverName,
            'max_players' => $config->maxPlayers,
            'cycle_enabled' => $config->cycleEnabled,
            'server_type' => $config->serverType?->value,
            'tuning_type' => $config->tuningType?->value,
            'driver_password' => $config->driverPassword,
            'admin_password' => $config->adminPassword,
            'spectator_password' => $config->spectatorPassword,
            'mode' => $config->mode?->value,
            'track_id' => $this->raceTrackIdFor($config->trackRef, $config->mode),
            'weather' => $config->weather?->value,
            'weather_behaviour' => $config->weatherBehaviour?->value,
            'initial_grip' => $config->initialGrip?->value,
            'car_ids' => $cars,
            'sessions' => $config->sessions,
        ];
    }

    private function raceTrackIdFor(?string $token, ?TrackMode $mode): ?int
    {
        if ($token === null || $mode === null) {
            return null;
        }

        $track = Track::query()->where('token', $token)->where('mode', $mode)->first();

        if ($track === null) {
            return null;
        }

        if ($mode === TrackMode::RaceWeekend) {
            return $track->id;
        }

        return Track::query()->where('paired_track_id', $track->id)->where('mode', TrackMode::RaceWeekend)->value('id');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeOverride(Server $server): ?array
    {
        $override = ServerOverride::query()
            ->where('server_id', $server->id)
            ->active(CarbonImmutable::now())
            ->with('creator:id,name')
            ->orderByDesc('id')
            ->first();

        if ($override === null) {
            return null;
        }

        $plan = $override->plan;
        $cars = Car::query()->whereIn('internal_name', (array) ($plan['car_refs'] ?? []))->orderBy('display_name')->pluck('display_name')->all();
        $track = Track::query()->where('token', (string) ($plan['track_ref'] ?? ''))->first();

        return [
            'id' => $override->id,
            'created_by' => $override->creator?->name,
            'created_at' => $override->created_at?->toIso8601String(),
            'until' => $override->until?->toIso8601String(),
            'summary' => [
                'mode' => $plan['mode'] ?? null,
                'track' => $track?->display,
                'server_name' => $plan['server_name'] ?? null,
                'max_players' => $plan['max_players'] ?? null,
                'cars' => $cars,
                'weather' => $plan['weather'] ?? null,
                'weather_behaviour' => $plan['weather_behaviour'] ?? null,
                'initial_grip' => $plan['initial_grip'] ?? null,
                'server_type' => $plan['server_type'] ?? null,
                'tuning_type' => $plan['tuning_type'] ?? null,
                'cycle_enabled' => $plan['cycle_enabled'] ?? null,
                'practice_seconds' => $plan['practice_seconds'] ?? null,
                'qualifying_seconds' => $plan['qualifying_seconds'] ?? null,
                'warmup_seconds' => $plan['warmup_seconds'] ?? null,
                'race_duration_type' => $plan['race_duration_type'] ?? null,
                'race_seconds' => $plan['race_seconds'] ?? null,
                'race_laps' => $plan['race_laps'] ?? null,
                'mandatory_pitstop' => $plan['mandatory_pitstop']['enabled'] ?? false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $tracks = Track::query()
            ->where('mode', TrackMode::RaceWeekend)
            ->where('active', true)
            ->with('pairedTrack:id,max_pit_slot,active')
            ->orderBy('display')
            ->orderBy('id')
            ->get()
            ->map(fn (Track $track): array => [
                'id' => $track->id,
                'display' => $track->display,
                'maxPitSlot' => $track->max_pit_slot,
                'lengthM' => $track->length_m,
                'country' => $track->country,
                'practiceTrack' => $track->pairedTrack !== null && $track->pairedTrack->active
                    ? ['id' => $track->pairedTrack->id, 'maxPitSlot' => $track->pairedTrack->max_pit_slot]
                    : null,
            ])
            ->values()
            ->all();

        return [
            'tracks' => $tracks,
            'cars' => CatalogCarResource::collection(
                Car::query()->where('active', true)->orderBy('display_name')->orderBy('id')->get(),
            )->resolve(),
            'enums' => [
                'mode' => array_column(TrackMode::cases(), 'value'),
                'server_type' => array_column(ServerType::cases(), 'value'),
                'tuning_type' => array_column(TuningType::cases(), 'value'),
                'weather' => array_column(WeatherType::cases(), 'value'),
                'weather_behaviour' => array_column(WeatherBehaviour::cases(), 'value'),
                'initial_grip' => array_column(InitialGrip::cases(), 'value'),
                'race_duration_type' => array_column(RaceDurationType::cases(), 'value'),
            ],
        ];
    }
}
