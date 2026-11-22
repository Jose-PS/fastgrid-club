<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\RunCatalogSync;
use App\Actions\Catalog\SetProposable;
use App\Enums\TrackMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCatalogProposableRequest;
use App\Integration\Server\ServerDriverException;
use App\Models\Car;
use App\Models\Server;
use App\Models\Track;
use App\Models\User;
use App\Support\AdminServerStatus;
use App\Support\TrackSvg;
use App\Support\Translations;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Translations $translations, TrackSvg $svg, WeekCalendar $calendar, AdminServerStatus $status): Response
    {
        $server = Server::query()->where('is_active', true)->orderBy('id')->first();

        $tracks = Track::query()
            ->where('mode', TrackMode::RaceWeekend)
            ->orderBy('display')
            ->orderBy('id')
            ->get()
            ->map(fn (Track $track): array => [
                'id' => $track->id,
                'display' => $track->display,
                'layout' => $track->layout,
                'country' => $track->country,
                'max_pit_slot' => $track->max_pit_slot,
                'active' => $track->active,
                'proposable' => $track->proposable,
                'has_practice' => $track->paired_track_id !== null,
                'has_svg' => $svg->has($svg->slugFor($track)),
            ]);

        return Inertia::render('admin/catalog', [
            'pageTranslations' => $translations->forGroups(['admin']),
            'tracks' => $tracks->values()->all(),
            'canSync' => $server !== null,
            'lastSync' => $server === null ? null : $status->lastCatalogSync($server),
            'missingSvgWeeks' => $this->missingSvgWeeks($svg, $calendar),
            'cars' => Car::query()
                ->orderBy('display_name')
                ->orderBy('id')
                ->get(['id', 'display_name', 'brand', 'classes', 'active', 'proposable', 'is_mod'])
                ->map(fn (Car $car): array => [
                    'id' => $car->id,
                    'display_name' => $car->display_name,
                    'brand' => $car->brand,
                    'classes' => $car->classes,
                    'active' => $car->active,
                    'proposable' => $car->proposable,
                    'is_mod' => $car->is_mod,
                ])
                ->all(),
        ]);
    }

    public function sync(Request $request, RunCatalogSync $runCatalogSync): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $server = Server::query()->where('is_active', true)->orderBy('id')->firstOrFail();

        try {
            $runCatalogSync->handle($server, $user);
        } catch (ServerDriverException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.catalog.syncFailed')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.catalog.syncDone')]);

        return back();
    }

    public function track(UpdateCatalogProposableRequest $request, Track $track, SetProposable $setProposable): RedirectResponse
    {
        abort_unless($track->mode === TrackMode::RaceWeekend, 404);

        $user = $request->user();
        assert($user instanceof User);

        $setProposable->handle($user, $track, $request->proposable());

        return back();
    }

    public function car(UpdateCatalogProposableRequest $request, Car $car, SetProposable $setProposable): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $setProposable->handle($user, $car, $request->proposable());

        return back();
    }

    /**
     * @return array<int, array{when: string, track: string}>
     */
    private function missingSvgWeeks(TrackSvg $svg, WeekCalendar $calendar): array
    {
        $server = Server::query()->where('is_active', true)->orderBy('id')->first();

        if ($server === null) {
            return [];
        }

        $currentStart = $calendar->weekStartFor(CarbonImmutable::now());
        $missing = [];

        foreach (['current' => $currentStart, 'next' => $calendar->nextWeekStart($currentStart)] as $when => $startsAt) {
            $track = $server->weeks()->with('track')->where('starts_at', $startsAt)->first()?->track;

            if ($track !== null && ! $svg->has($svg->slugFor($track))) {
                $missing[] = ['when' => $when, 'track' => $track->display];
            }
        }

        return $missing;
    }
}
