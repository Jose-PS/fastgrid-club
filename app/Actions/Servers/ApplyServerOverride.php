<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAudit;
use App\Enums\ConfigApplicationStatus;
use App\Integration\Server\Data\ConfigPlan;
use App\Integration\Server\Data\ServerSecrets;
use App\Models\Server;
use App\Models\ServerOverride;
use App\Models\User;
use App\Models\Week;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApplyServerOverride
{
    private const LOCK_SECONDS = 300;

    public const UNTIL_FORMAT = 'Y-m-d\TH:i';

    public function __construct(
        private readonly BuildOverridePlan $buildPlan,
        private readonly ApplyServerConfig $applyConfig,
        private readonly WeekCalendar $calendar,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $admin, Server $server, array $input, ?CarbonImmutable $now = null): ServerOverrideResult
    {
        $now ??= CarbonImmutable::now();

        $plan = $this->buildPlan->handle($server, $input);
        $until = $this->until($input['until'] ?? null, $now);
        $secrets = $this->secrets($input);

        $lock = Cache::lock("server:{$server->id}:reconcile", self::LOCK_SECONDS);

        if (! $lock->get()) {
            return new ServerOverrideResult(ServerOverrideResult::BUSY);
        }

        try {
            return $this->apply($admin, $server, $plan->toArray(), $until, $secrets, $now);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $planData
     */
    private function apply(User $admin, Server $server, array $planData, ?CarbonImmutable $until, ServerSecrets $secrets, CarbonImmutable $now): ServerOverrideResult
    {
        $week = $this->currentWeek($server, $now);
        $key = (string) Str::uuid();
        $plan = ConfigPlan::fromArray($planData);

        $application = $this->applyConfig->handle($server, $plan, $week, "override:{$key}", $plan->mode, $secrets, false);

        $summary = [
            'until' => $until?->toIso8601String(),
            'plan' => $planData,
            'changed_access' => $this->accessChanges($secrets),
        ];

        if ($application->status !== ConfigApplicationStatus::Ok) {
            $this->recordAudit->handle($admin, 'server.override_failed', $server, null, $summary + ['error' => $application->error]);

            return new ServerOverrideResult(ServerOverrideResult::FAILED, $application->error);
        }

        [$override, $previous] = DB::transaction(function () use ($server, $week, $key, $planData, $until, $admin, $now): array {
            $previous = ServerOverride::query()->where('server_id', $server->id)->whereNull('ended_at')->get();

            foreach ($previous as $old) {
                $old->forceFill(['ended_at' => $now])->save();
            }

            $override = ServerOverride::query()->create([
                'key' => $key,
                'server_id' => $server->id,
                'week_id' => $week?->id,
                'plan' => $planData,
                'until' => $until,
                'created_by' => $admin->id,
            ]);

            return [$override, $previous];
        });

        $this->recordAudit->handle(
            $admin,
            'server.override_applied',
            $server,
            $previous->isEmpty() ? null : ['replaced_override_ids' => $previous->pluck('id')->all()],
            $summary + ['override_id' => $override->id],
        );

        return new ServerOverrideResult(ServerOverrideResult::APPLIED, null, $override);
    }

    private function currentWeek(Server $server, CarbonImmutable $now): ?Week
    {
        return Week::query()
            ->where('server_id', $server->id)
            ->where('starts_at', $this->calendar->scheduleWindowAt($now)->weekStartsAt)
            ->first();
    }

    private function until(mixed $value, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $until = CarbonImmutable::createFromFormat(self::UNTIL_FORMAT, (string) $value, (string) config('mvp.timezone'));

        if ($until === null || $until->lessThanOrEqualTo($now)) {
            throw ValidationException::withMessages(['until' => __('admin.override.errUntil')]);
        }

        return $until->setTimezone('UTC');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function secrets(array $input): ServerSecrets
    {
        return new ServerSecrets(
            $this->secret($input, 'driver_password', 'clear_driver_password'),
            $this->secret($input, 'admin_password', 'clear_admin_password'),
            $this->secret($input, 'spectator_password', 'clear_spectator_password'),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function secret(array $input, string $key, string $clearKey): ?string
    {
        $value = $input[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return (bool) ($input[$clearKey] ?? false) ? '' : null;
    }

    /**
     * @return array<string, string>
     */
    private function accessChanges(ServerSecrets $secrets): array
    {
        $describe = fn (?string $value): string => $value === null ? 'kept' : ($value === '' ? 'cleared' : 'set');

        return [
            'driver' => $describe($secrets->driverPassword),
            'admin' => $describe($secrets->adminPassword),
            'spectator' => $describe($secrets->spectatorPassword),
        ];
    }
}
