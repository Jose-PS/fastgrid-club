<?php

declare(strict_types=1);

namespace App\Actions\Social;

use App\Enums\SocialPostType;
use App\Enums\WeekStatus;
use App\Integration\Server\Log\SessionType;
use App\Jobs\RenderSocialPostImageJob;
use App\Mail\SocialPostReadyMail;
use App\Models\Car;
use App\Models\Driver;
use App\Models\Lap;
use App\Models\RaceResult;
use App\Models\ServerSession;
use App\Models\SocialPost;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class PrepareSocialPosts
{
    public const RECENT_HOURS = 48;

    public const RECENT_DAYS = 2;

    public const int MAX_LENGTH = 280;

    private const TOP_LAPS = 5;

    private const MONTHS = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];

    private const HASHTAGS = '#AssettoCorsa #SimRacing #FastGrid';

    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $created = 0;

        foreach ($this->weekAnnouncements($now) as $post) {
            $created += $this->store($post);
        }

        foreach ($this->dailyResults($now) as $post) {
            $created += $this->store($post);
        }

        foreach ($this->weeklyBestLaps($now) as $post) {
            $created += $this->store($post);
        }

        return $created;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function weekAnnouncements(CarbonImmutable $now): array
    {
        $weeks = Week::query()
            ->where('status', '!=', WeekStatus::Voting)
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $now->subHours(self::RECENT_HOURS))
            ->whereNotNull('track_id')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $existing = $this->existingKeys($weeks->map(fn (Week $week): string => $this->weekAnnouncementKey($week)));
        $posts = [];

        foreach ($weeks as $week) {
            $key = $this->weekAnnouncementKey($week);

            if (in_array($key, $existing, true)) {
                continue;
            }

            $week->load(['track', 'cars']);
            $circuit = $week->track?->display;

            if ($circuit === null) {
                continue;
            }

            $timezone = $this->timezone();
            $starts = $week->starts_at->toImmutable()->setTimezone($timezone);
            $ends = $week->ends_at->toImmutable()->setTimezone($timezone)->subSecond();
            $cars = array_values($week->cars->map(fn (Car $car): string => $car->display_name)->all());
            $title = $week->custom_title !== null && $week->custom_title !== '' ? $week->custom_title : null;
            $dates = $this->dateRange($starts, $ends);
            $head = "🏁 Nueva semana · New week: {$circuit}";
            $lines = $title !== null ? [$title, $dates] : [$dates];

            $posts[] = [
                'type' => SocialPostType::WeekAnnouncement,
                'key' => $key,
                'server_id' => $week->server_id,
                'week_id' => $week->id,
                'text' => $this->fitList($head, $lines, $this->carsLabel($cars), $cars, $this->footer('Vota y corre · Vote and race 👉')),
                'data' => [
                    'title' => $title,
                    'circuit' => $circuit,
                    'track_id' => $week->track_id,
                    'svg_slug' => $week->track->svg_slug,
                    'cars' => $cars,
                    'starts_at' => $starts->format('c'),
                    'ends_at' => $ends->format('c'),
                ],
            ];
        }

        return $posts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dailyResults(CarbonImmutable $now): array
    {
        $timezone = $this->timezone();
        $today = $now->setTimezone($timezone)->startOfDay();
        $since = $today->subDays(self::RECENT_DAYS);

        $results = RaceResult::query()
            ->where('position', 1)
            ->where('classified', true)
            ->whereHas('session', fn ($query) => $query
                ->where('type', SessionType::Race)
                ->where('started_at', '>=', $since->utc())
                ->where('started_at', '<', $today->utc()))
            ->with(['session.track', 'session.week.track', 'driver', 'car'])
            ->get()
            ->unique('server_session_id')
            ->sortBy(fn (RaceResult $result): string => $result->session->started_at->format('Y-m-d H:i:s').'-'.str_pad((string) $result->session->id, 12, '0', STR_PAD_LEFT));

        $days = $results->groupBy(fn (RaceResult $result): string => $result->session->server_id.'|'.$result->session->started_at->toImmutable()->setTimezone($timezone)->format('Y-m-d'));
        $existing = $this->existingKeys($days->keys()->map(fn (string $group): string => $this->dailyKey($group)));
        $posts = [];

        foreach ($days as $group => $dayResults) {
            $key = $this->dailyKey((string) $group);

            if (in_array($key, $existing, true)) {
                continue;
            }

            [$serverId, $date] = explode('|', (string) $group);
            $first = $dayResults->first();
            $day = CarbonImmutable::parse($date, $timezone);
            $circuit = $this->sessionTrack($first->session)?->display;

            $races = array_values($dayResults->map(fn (RaceResult $result): array => [
                'session_id' => $result->server_session_id,
                'started_at' => $result->session->started_at->toImmutable()->setTimezone($timezone)->format('H:i'),
                'circuit' => $this->sessionTrack($result->session)?->display,
                'winner' => $this->driverName($result->driver),
                'car' => $result->car?->display_name,
                'total_ms' => $result->total_ms,
                'best_lap_ms' => $result->best_lap_ms,
            ])->all());

            $head = '🏁 Resultados · Results '.$day->format('j/n').($circuit !== null ? ": {$circuit}" : '');

            $posts[] = [
                'type' => SocialPostType::DailyResults,
                'key' => $key,
                'server_id' => (int) $serverId,
                'week_id' => $first->session->week_id,
                'text' => $this->dailyText($head, $races),
                'data' => [
                    'date' => $date,
                    'circuit' => $circuit,
                    'track_id' => $this->sessionTrack($first->session)?->id,
                    'svg_slug' => $this->sessionTrack($first->session)?->svg_slug,
                    'races' => $races,
                ],
            ];
        }

        return $posts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function weeklyBestLaps(CarbonImmutable $now): array
    {
        $weeks = Week::query()
            ->where('status', WeekStatus::Finished)
            ->where('ends_at', '>=', $now->subHours(self::RECENT_HOURS))
            ->whereNotNull('track_id')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $existing = $this->existingKeys($weeks->map(fn (Week $week): string => $this->weeklyKey($week)));
        $posts = [];

        foreach ($weeks as $week) {
            $key = $this->weeklyKey($week);

            if (in_array($key, $existing, true)) {
                continue;
            }

            $track = Track::query()->find($week->track_id);

            if ($track === null) {
                continue;
            }

            $rows = $this->bestLaps($week, $track);

            if ($rows === []) {
                continue;
            }

            $timezone = $this->timezone();
            $period = $this->dateRange($week->starts_at->toImmutable()->setTimezone($timezone), $week->ends_at->toImmutable()->setTimezone($timezone)->subSecond());

            $posts[] = [
                'type' => SocialPostType::WeeklyBestLaps,
                'key' => $key,
                'server_id' => $week->server_id,
                'week_id' => $week->id,
                'text' => $this->lapsText("🏆 Mejores vueltas · Best laps {$period}: {$track->display}", $rows),
                'data' => [
                    'circuit' => $track->display,
                    'track_id' => $track->id,
                    'svg_slug' => $track->svg_slug,
                    'starts_at' => $week->starts_at->toImmutable()->setTimezone($this->timezone())->format('c'),
                    'ends_at' => $week->ends_at->toImmutable()->setTimezone($this->timezone())->format('c'),
                    'rows' => $rows,
                ],
            ];
        }

        return $posts;
    }

    /**
     * @return list<array{position: int, driver: string, car: string|null, lap_ms: int}>
     */
    private function bestLaps(Week $week, Track $track): array
    {
        $circuitId = $track->paired_track_id ?? $track->id;
        $trackIds = Track::query()
            ->where('id', $circuitId)
            ->orWhere('paired_track_id', $circuitId)
            ->pluck('id');

        $laps = Lap::query()
            ->where('server_id', $week->server_id)
            ->where('recorded_at', '>=', $week->starts_at)
            ->where('recorded_at', '<', $week->ends_at)
            ->whereIn('track_id', $trackIds)
            ->whereNotNull('car_id')
            ->where(fn ($query) => $query->whereNull('is_valid')->orWhere('is_valid', true))
            ->orderBy('lap_ms')
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->unique('driver_id')
            ->take(self::TOP_LAPS)
            ->values();

        $laps->load(['driver', 'car']);

        return array_values($laps->map(fn (Lap $lap, int $index): array => [
            'position' => $index + 1,
            'driver' => $this->driverName($lap->driver),
            'car' => $lap->car?->display_name,
            'lap_ms' => $lap->lap_ms,
        ])->all());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(array $attributes): int
    {
        $post = SocialPost::query()->firstOrCreate(['key' => $attributes['key']], $attributes);

        if (! $post->wasRecentlyCreated) {
            return 0;
        }

        RenderSocialPostImageJob::dispatch($post);

        User::query()
            ->where('is_admin', true)
            ->whereNotNull('email')
            ->each(fn (User $admin) => Mail::to($admin)->send(new SocialPostReadyMail($post)));

        return 1;
    }

    /**
     * @param  Collection<int, string>  $keys
     * @return list<string>
     */
    private function existingKeys(Collection $keys): array
    {
        if ($keys->isEmpty()) {
            return [];
        }

        return array_values(SocialPost::query()
            ->whereIn('key', $keys->values())
            ->get(['key'])
            ->map(fn (SocialPost $post): string => $post->key)
            ->all());
    }

    private function weekAnnouncementKey(Week $week): string
    {
        return "week_announcement:{$week->id}";
    }

    private function weeklyKey(Week $week): string
    {
        return "weekly_best_laps:{$week->id}";
    }

    private function dailyKey(string $group): string
    {
        [$serverId, $date] = explode('|', $group);

        return "daily_results:{$serverId}:{$date}";
    }

    private function timezone(): string
    {
        return (string) config('mvp.timezone');
    }

    private function driverName(Driver $driver): string
    {
        return (string) ($driver->last_name ?? '');
    }

    private function sessionTrack(ServerSession $session): ?Track
    {
        return $session->track ?? $session->week?->track;
    }

    private function shortName(string $name, int $limit): string
    {
        return mb_strimwidth($name, 0, $limit, '…');
    }

    /**
     * @param  list<array<string, mixed>>  $races
     */
    private function dailyText(string $head, array $races): string
    {
        $wins = [];

        foreach ($races as $race) {
            $name = $this->shortName((string) $race['winner'], 20);
            $wins[$name] = ($wins[$name] ?? 0) + 1;
        }

        $entries = [];

        foreach ($wins as $name => $count) {
            $entries[] = $count > 1 ? "{$name} ×{$count}" : (string) $name;
        }

        $cars = array_values(array_unique(array_filter(array_column($races, 'car'), fn (?string $car): bool => $car !== null)));
        $lines = $cars === [] ? [] : [$this->listLine($this->carsLabel($cars), $cars, count($cars))];

        return $this->fitList($head, $lines, 'Ganadores · Winners', $entries, $this->footer(null));
    }

    /**
     * @param  list<array{position: int, driver: string, car: string|null, lap_ms: int}>  $rows
     */
    private function lapsText(string $head, array $rows): string
    {
        $cars = array_values(array_unique(array_column($rows, 'car')));
        $single = count($cars) === 1 && $cars[0] !== null;
        $footer = $this->footer(null);
        $variants = [];

        $variants[] = $single
            ? [['Coche · Car: '.$cars[0]], false]
            : [[], true];
        $variants[] = [[], false];

        foreach ($variants as [$prefix, $withCars]) {
            $lines = array_map(fn (array $row): string => "{$row['position']}. ".$this->shortName($row['driver'], 20).' '.$this->formatTime($row['lap_ms']).($withCars && $row['car'] !== null ? ' ('.$this->shortName($row['car'], 16).')' : ''), $rows);
            $text = $this->compose($head, [...$prefix, ...$lines], $footer);

            if ($lines !== [] && str_contains($text, end($lines))) {
                return $text;
            }
        }

        return $this->compose($head, $lines, $footer);
    }

    private function formatTime(int $milliseconds): string
    {
        $hours = intdiv($milliseconds, 3600000);
        $minutes = intdiv($milliseconds % 3600000, 60000);
        $seconds = intdiv($milliseconds % 60000, 1000);
        $millis = $milliseconds % 1000;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d.%03d', $hours, $minutes, $seconds, $millis);
        }

        return sprintf('%d:%02d.%03d', $minutes, $seconds, $millis);
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $items
     */
    private function fitList(string $head, array $lines, string $label, array $items, string $footer): string
    {
        for ($shown = count($items); $shown >= 1; $shown--) {
            $line = $this->listLine($label, $items, $shown);
            $text = $this->compose($head, [...$lines, $line], $footer);

            if (str_contains($text, $line)) {
                return $text;
            }
        }

        return $this->compose($head, $lines, $footer);
    }

    /**
     * @param  list<string>  $items
     */
    private function listLine(string $label, array $items, int $shown): string
    {
        $rest = count($items) - $shown;

        return $label.': '.implode(', ', array_slice($items, 0, $shown)).($rest > 0 ? " y {$rest} más · +{$rest} more" : '');
    }

    /**
     * @param  list<string>  $cars
     */
    private function carsLabel(array $cars): string
    {
        return count($cars) === 1 ? 'Coche · Car' : 'Coches · Cars';
    }

    private function dateRange(CarbonImmutable $starts, CarbonImmutable $ends): string
    {
        if ($starts->month === $ends->month) {
            return $starts->day.'–'.$ends->day.' '.self::MONTHS[$ends->month];
        }

        return $starts->day.' '.self::MONTHS[$starts->month].' – '.$ends->day.' '.self::MONTHS[$ends->month];
    }

    private function footer(?string $callToAction): string
    {
        $url = rtrim((string) config('app.url'), '/');

        return ($callToAction === null ? $url : "{$callToAction} {$url}")."\n".self::HASHTAGS;
    }

    /**
     * @param  list<string>  $lines
     */
    private function compose(string $head, array $lines, string $footer): string
    {
        $text = mb_strimwidth($head, 0, self::MAX_LENGTH - mb_strlen($footer) - 1, '…');

        foreach ($lines as $line) {
            $candidate = $text."\n".$line;

            if (mb_strlen($candidate."\n".$footer) > self::MAX_LENGTH) {
                break;
            }

            $text = $candidate;
        }

        return $text."\n".$footer;
    }
}
