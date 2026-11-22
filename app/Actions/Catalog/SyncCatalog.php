<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Alerts\CloseAlert;
use App\Actions\Audit\RecordAudit;
use App\Actions\Proposals\ProposalListing;
use App\Enums\AlertType;
use App\Enums\TrackMode;
use App\Integration\Server\Data\Catalog;
use App\Integration\Server\Data\CatalogCar;
use App\Integration\Server\Data\CatalogTrack;
use App\Models\Car;
use App\Models\CatalogSync;
use App\Models\Server;
use App\Models\Track;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SyncCatalog
{
    public function __construct(
        private readonly RecordAudit $recordAudit,
        private readonly ProposalListing $listing,
        private readonly CloseAlert $closeAlert,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function countries(): array
    {
        return json_decode((string) file_get_contents(database_path('data/track-countries.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, string>|null  $countries
     */
    public function handle(Server $server, Catalog $catalog, ?array $countries = null, ?User $actor = null): CatalogSync
    {
        $countries ??= self::countries();
        $startedAt = now();

        $sync = DB::transaction(function () use ($server, $catalog, $countries, $startedAt, $actor): CatalogSync {
            [$tracks, $unpaired] = $this->syncTracks($catalog->tracks, $countries, $startedAt);
            $cars = $this->syncCars($catalog->cars, $startedAt);

            $sync = CatalogSync::query()->create([
                'server_id' => $server->id,
                'summary' => [
                    'cars' => $cars,
                    'tracks' => $tracks,
                    'unpaired_race_tracks' => $unpaired,
                ],
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            $this->recordAudit->handle($actor, 'catalog.synced', $sync, null, $sync->summary);

            return $sync;
        });

        $this->listing->forgetCatalog();
        $this->closeAlert->forSubject(AlertType::CatalogStale, $server);

        return $sync;
    }

    /**
     * @param  list<CatalogTrack>  $catalogTracks
     * @param  array<string, string>  $countries
     * @return array{0: array{created: int, updated: int, deactivated: int}, 1: list<string>}
     */
    private function syncTracks(array $catalogTracks, array $countries, CarbonInterface $syncedAt): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deactivated' => 0];
        $existing = Track::query()->get()->keyBy(fn (Track $track): string => "{$track->mode->value}|{$track->token}");
        $seenIds = [];
        $practiceByCircuit = [];
        $unpaired = [];

        $ordered = [
            ...array_filter($catalogTracks, fn (CatalogTrack $track): bool => $track->mode === TrackMode::Practice),
            ...array_filter($catalogTracks, fn (CatalogTrack $track): bool => $track->mode === TrackMode::RaceWeekend),
        ];

        foreach ($ordered as $catalogTrack) {
            $circuit = "{$catalogTrack->track}|{$catalogTrack->layout}";
            $pairedTrackId = null;

            if ($catalogTrack->mode === TrackMode::RaceWeekend) {
                $pairedTrackId = $practiceByCircuit[$circuit] ?? null;

                if ($pairedTrackId === null) {
                    $unpaired[] = $catalogTrack->trackRef;
                }
            }

            $key = "{$catalogTrack->mode->value}|{$catalogTrack->trackRef}";
            $track = $existing->get($key);
            $isNew = $track === null;

            $track ??= new Track([
                'mode' => $catalogTrack->mode,
                'token' => $catalogTrack->trackRef,
                'proposable' => $catalogTrack->mode === TrackMode::Practice || $pairedTrackId !== null,
            ]);

            $track->fill([
                'display' => $catalogTrack->display,
                'track' => $catalogTrack->track,
                'layout' => $catalogTrack->layout,
                'event_name' => $catalogTrack->eventName,
                'length_m' => $catalogTrack->lengthM,
                'max_pit_slot' => $catalogTrack->maxPitSlot,
                'paired_track_id' => $pairedTrackId,
                'active' => true,
            ]);

            if ($track->country === null && isset($countries[$catalogTrack->track])) {
                $track->country = $countries[$catalogTrack->track];
            }

            $this->count($counts, $isNew, $track->isDirty());

            $track->synced_at = $syncedAt;
            $track->save();

            $existing->put($key, $track);
            $seenIds[] = $track->id;

            if ($catalogTrack->mode === TrackMode::Practice) {
                $practiceByCircuit[$circuit] ??= $track->id;
            }
        }

        $counts['deactivated'] = Track::query()
            ->where('active', true)
            ->whereNotIn('id', $seenIds)
            ->update(['active' => false]);

        return [$counts, array_values(array_unique($unpaired))];
    }

    /**
     * @param  list<CatalogCar>  $catalogCars
     * @return array{created: int, updated: int, deactivated: int}
     */
    private function syncCars(array $catalogCars, CarbonInterface $syncedAt): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deactivated' => 0];
        $existing = Car::query()->get()->keyBy('internal_name');
        $seen = [];

        foreach ($catalogCars as $catalogCar) {
            $car = $existing->get($catalogCar->carRef);
            $isNew = $car === null;

            $car ??= new Car([
                'internal_name' => $catalogCar->carRef,
                'proposable' => ! $catalogCar->isMod,
            ]);

            $car->fill([
                'display_name' => $catalogCar->displayName,
                'type' => $catalogCar->type,
                'era' => $catalogCar->era,
                'engine' => $catalogCar->engine,
                'pi' => $catalogCar->pi === null ? null : round($catalogCar->pi, 1),
                'brand' => Car::brandFromDisplayName($catalogCar->displayName),
                'runtime_name' => $catalogCar->runtimeName,
                'classes' => $catalogCar->classes !== [] ? $catalogCar->classes : $this->racingClasses($catalogCar),
                'is_mod' => $catalogCar->isMod,
                'active' => true,
            ]);

            $this->count($counts, $isNew, $car->isDirty());

            $car->synced_at = $syncedAt;
            $car->save();

            $existing->put($catalogCar->carRef, $car);
            $seen[] = $catalogCar->carRef;
        }

        $counts['deactivated'] = Car::query()
            ->where('active', true)
            ->whereNotIn('internal_name', $seen)
            ->update(['active' => false]);

        return $counts;
    }

    /**
     * @param  array{created: int, updated: int, deactivated: int}  $counts
     */
    private function count(array &$counts, bool $isNew, bool $changed): void
    {
        if ($isNew) {
            $counts['created']++;
        } elseif ($changed) {
            $counts['updated']++;
        }
    }

    /**
     * @return list<string>
     */
    private function racingClasses(CatalogCar $car): array
    {
        if ($car->type !== 'race') {
            return [];
        }

        $name = $car->displayName;
        $classes = [];

        if (preg_match('/SF-25|F2004|Formula|\bF1\b/', $name)) {
            $classes[] = 'f1';
        }

        if (preg_match('/\bGT3\b/', $name) && ! str_contains($name, 'GT3 Cup')) {
            $classes[] = 'gt3';
        }

        if (preg_match('/\bGT2\b/', $name)) {
            $classes[] = 'gt2';
        }

        if (preg_match('/\bGT4\b/', $name)) {
            $classes[] = 'gt4';
        }

        if (preg_match('/Cup|Challenge|Trofeo|Academy|M2 CS Racing/', $name)) {
            $classes[] = 'cup';
        }

        return $classes;
    }
}
