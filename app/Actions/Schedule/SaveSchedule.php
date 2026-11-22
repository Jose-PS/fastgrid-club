<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Actions\Audit\RecordAudit;
use App\Actions\Servers\ReapplyServerConfig;
use App\Enums\ReconcileResult;
use App\Models\ScheduleSegment;
use App\Models\ScheduleVersion;
use App\Models\Server;
use App\Models\User;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SaveSchedule
{
    public const APPLY_TOMORROW = 'tomorrow';

    public const APPLY_NOW = 'now';

    public function __construct(
        private readonly RecordAudit $recordAudit,
        private readonly ReapplyServerConfig $reapplyServerConfig,
        private readonly WeekCalendar $weekCalendar,
    ) {}

    /**
     * @param  array<int, array{weekday: int, starts_at: string, practice_minutes: int|null, qualifying_minutes: int|null, race_duration_type: string|null, race_minutes: int|null, race_laps: int|null, start_hour: int, weather: string}>  $segments
     */
    public function handle(User $user, array $segments, string $apply): ScheduleVersion
    {
        $effectiveFrom = $apply === self::APPLY_NOW
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::now((string) config('mvp.timezone'))->addDay()->startOfDay()->setTimezone('UTC');

        $saved = DB::transaction(function () use ($user, $segments, $apply, $effectiveFrom): ScheduleVersion {
            $discardedPending = ScheduleVersion::query()
                ->where('effective_from', '>', CarbonImmutable::now('UTC'))
                ->delete();

            $version = ScheduleVersion::query()->create([
                'effective_from' => $effectiveFrom,
                'created_by' => $user->id,
            ]);

            foreach ($segments as $segment) {
                $version->segments()->save(new ScheduleSegment($segment));
            }

            $this->recordAudit->handle($user, 'schedule.saved', $version, null, [
                'apply' => $apply,
                'effective_from' => $effectiveFrom->toIso8601String(),
                'segments' => count($segments),
                'discarded_pending' => $discardedPending,
            ]);

            return $version;
        });

        $this->weekCalendar->forgetSchedule();

        return $saved;
    }

    /**
     * @return array<int, ReconcileResult>
     */
    public function reapplyActiveServers(User $user): array
    {
        return Server::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (Server $server): ReconcileResult => $this->reapplyServerConfig->handle($user, $server))
            ->all();
    }
}
