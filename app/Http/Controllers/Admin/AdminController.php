<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Alerts\AlertListing;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use App\Models\Week;
use App\Support\AdminServerStatus;
use App\Support\Heartbeats;
use App\Support\Translations;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function __invoke(Request $request, Translations $translations, WeekCalendar $calendar, AlertListing $alerts, AdminServerStatus $status, Heartbeats $heartbeats): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        $server = Server::query()->where('is_active', true)->orderBy('id')->first();
        $now = CarbonImmutable::now();
        $currentStart = $calendar->weekStartFor($now);

        return Inertia::render('admin/index', [
            'pageTranslations' => $translations->forGroups(['admin']),
            'weeks' => [
                'current' => $server === null ? null : $this->week($server, $currentStart),
                'next' => $server === null ? null : $this->week($server, $calendar->nextWeekStart($currentStart)),
            ],
            'server' => $server === null ? null : ['name' => $server->name, ...$status->summary($server, $now)],
            'missingPasskey' => ! $user->passkeys()->exists(),
            'alerts' => $alerts->open(),
            'heartbeats' => $heartbeats->overview($now),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function week(Server $server, CarbonImmutable $startsAt): ?array
    {
        $week = $server->weeks()
            ->with(['track:id,display', 'cars:id,display_name'])
            ->where('starts_at', $startsAt)
            ->first();

        if (! $week instanceof Week) {
            return null;
        }

        return [
            'id' => $week->id,
            'starts_at' => $week->starts_at->toIso8601String(),
            'ends_at' => $week->ends_at->toIso8601String(),
            'status' => $week->status->value,
            'config_source' => $week->config_source?->value,
            'custom_title' => $week->custom_title,
            'track' => $week->track?->display,
            'cars' => $week->cars->sortBy('display_name')->pluck('display_name')->values()->all(),
        ];
    }
}
