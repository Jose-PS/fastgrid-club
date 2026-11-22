<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Schedule\SaveSchedule;
use App\Enums\ReconcileResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveScheduleRequest;
use App\Models\ScheduleSegment;
use App\Models\ScheduleVersion;
use App\Models\User;
use App\Support\Translations;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleController extends Controller
{
    public function index(Translations $translations): Response
    {
        $now = CarbonImmutable::now();
        $timezone = (string) config('mvp.timezone');
        $version = ScheduleVersion::query()->orderByDesc('effective_from')->orderByDesc('id')->with('segments')->first();

        return Inertia::render('admin/schedule', [
            'pageTranslations' => $translations->forGroups(['admin']),
            'schedule' => $version === null ? null : [
                'pending' => $version->effective_from->greaterThan($now),
                'effective_from' => CarbonImmutable::instance($version->effective_from)->setTimezone($timezone)->format('d/m/Y H:i'),
                'segments' => $version->segments->map(fn (ScheduleSegment $segment): array => [
                    'weekday' => $segment->weekday,
                    'starts_at' => substr($segment->starts_at, 0, 5),
                    'practice_minutes' => $segment->practice_minutes,
                    'qualifying_minutes' => $segment->qualifying_minutes,
                    'race_duration_type' => $segment->race_duration_type?->value,
                    'race_minutes' => $segment->race_minutes,
                    'race_laps' => $segment->race_laps,
                    'start_hour' => $segment->start_hour,
                    'weather' => $segment->weather->value,
                ])->values()->all(),
            ],
        ]);
    }

    public function store(SaveScheduleRequest $request, SaveSchedule $saveSchedule): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $saveSchedule->handle($user, $request->segments(), $request->apply());

        if ($request->apply() === SaveSchedule::APPLY_NOW) {
            $results = $saveSchedule->reapplyActiveServers($user);

            Inertia::flash('toast', [
                'type' => in_array(ReconcileResult::Failed, $results, true) ? 'error' : 'success',
                'message' => __(in_array(ReconcileResult::Failed, $results, true) ? 'admin.schedule.savedNowFailed' : 'admin.schedule.savedNow'),
            ]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.schedule.savedTomorrow')]);
        }

        return to_route('admin.schedule.index');
    }
}
