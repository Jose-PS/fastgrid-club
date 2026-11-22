<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ConfigSource;
use App\Models\Proposal;
use App\Models\Week;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class LastVoting
{
    private const string PROPOSAL_CARS = "(select coalesce(json_agg(json_build_object('id', cars.id, 'displayName', cars.display_name) order by cars.display_name, cars.id), cast('[]' as json)) from proposal_cars inner join cars on cars.id = proposal_cars.car_id where proposal_cars.proposal_id = proposals.id)";

    private const string WEEK_CARS = "(select coalesce(json_agg(json_build_object('id', cars.id, 'displayName', cars.display_name) order by cars.display_name, cars.id), cast('[]' as json)) from week_cars inner join cars on cars.id = week_cars.car_id where week_cars.week_id = weeks.id)";

    public function __construct(private readonly WeekPayload $weekPayload) {}

    public static function key(int $weekId): string
    {
        return "proposals.result.{$weekId}";
    }

    /**
     * @return array{
     *     week: array{id: int, startsAt: string, endsAt: string},
     *     source: string,
     *     winnerId: int|null,
     *     config: array{track: array{display: string, maxPitSlot: int}, cars: array<int, array{id: int, displayName: string}>, maxPlayers: int|null}|null,
     *     ranking: array<int, array{id: int, rank: int, track: array{display: string, maxPitSlot: int}, cars: array<int, array{id: int, displayName: string}>, votes: int}>,
     *     totalVotes: int
     * }|null
     */
    public function find(): ?array
    {
        $week = Week::query()
            ->leftJoin('tracks', 'tracks.id', '=', 'weeks.track_id')
            ->whereHas('server', fn (Builder $query) => $query->where('is_active', true))
            ->whereNotNull('weeks.resolved_at')
            ->select(['weeks.*', 'tracks.display as track_display', 'tracks.max_pit_slot as track_max_pit_slot'])
            ->selectRaw(self::WEEK_CARS.' as cars_json')
            ->orderByDesc('weeks.starts_at')
            ->orderBy('weeks.server_id')
            ->first();

        if ($week === null) {
            return null;
        }

        return Cache::remember(
            self::key($week->id),
            (int) config('mvp.proposals_cache_seconds'),
            fn (): array => $this->build($week),
        );
    }

    /**
     * @return array{
     *     week: array{id: int, startsAt: string, endsAt: string},
     *     source: string,
     *     winnerId: int|null,
     *     config: array{track: array{display: string, maxPitSlot: int}, cars: array<int, array{id: int, displayName: string}>, maxPlayers: int|null}|null,
     *     ranking: array<int, array{id: int, rank: int, track: array{display: string, maxPitSlot: int}, cars: array<int, array{id: int, displayName: string}>, votes: int}>,
     *     totalVotes: int
     * }
     */
    private function build(Week $week): array
    {
        $ranking = $this->ranking($week);
        $source = $week->config_source ?? ConfigSource::Fallback;

        return [
            'week' => [
                'id' => $week->id,
                'startsAt' => $this->weekPayload->iso($week->starts_at->toImmutable()),
                'endsAt' => $this->weekPayload->iso($week->ends_at->toImmutable()),
            ],
            'source' => $source->value,
            'winnerId' => $week->proposal_id,
            'config' => $source === ConfigSource::Proposal || $week->track_id === null ? null : [
                'track' => [
                    'display' => (string) $week->getAttribute('track_display'),
                    'maxPitSlot' => (int) $week->getAttribute('track_max_pit_slot'),
                ],
                'cars' => $this->cars($week->getAttribute('cars_json')),
                'maxPlayers' => $week->max_players,
            ],
            'ranking' => $ranking,
            'totalVotes' => array_sum(array_column($ranking, 'votes')),
        ];
    }

    /**
     * @return array<int, array{id: int, rank: int, track: array{display: string, maxPitSlot: int}, cars: array<int, array{id: int, displayName: string}>, votes: int}>
     */
    private function ranking(Week $week): array
    {
        return Proposal::query()
            ->join('tracks', 'tracks.id', '=', 'proposals.track_id')
            ->where('proposals.week_id', $week->id)
            ->whereNull('proposals.hidden_at')
            ->select(['proposals.id', 'tracks.display as track_display', 'tracks.max_pit_slot as track_max_pit_slot'])
            ->selectRaw(self::PROPOSAL_CARS.' as cars_json')
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->orderBy('proposals.created_at')
            ->orderBy('proposals.id')
            ->get()
            ->values()
            ->map(fn (Proposal $proposal, int $index): array => [
                'id' => $proposal->id,
                'rank' => $index + 1,
                'track' => [
                    'display' => (string) $proposal->getAttribute('track_display'),
                    'maxPitSlot' => (int) $proposal->getAttribute('track_max_pit_slot'),
                ],
                'cars' => $this->cars($proposal->getAttribute('cars_json')),
                'votes' => (int) $proposal->votes_count,
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, displayName: string}>
     */
    private function cars(mixed $json): array
    {
        $cars = json_decode((string) $json, true) ?? [];

        return array_map(fn (array $car): array => ['id' => (int) $car['id'], 'displayName' => (string) $car['displayName']], $cars);
    }
}
