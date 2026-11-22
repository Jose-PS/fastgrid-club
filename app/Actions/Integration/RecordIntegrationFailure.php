<?php

declare(strict_types=1);

namespace App\Actions\Integration;

use App\Actions\Alerts\OpenAlert;
use App\Enums\AlertType;
use App\Enums\IntegrationEventType;
use App\Mail\IntegrationFailingMail;
use App\Models\IntegrationEvent;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class RecordIntegrationFailure
{
    public const THRESHOLD = 5;

    private const SUMMARY_LIMIT = 500;

    private const COUNTER_TTL_SECONDS = 86400;

    private const MAIL_INTERVAL_SECONDS = 3600;

    public function __construct(private readonly OpenAlert $openAlert) {}

    public function handle(Server $server, IntegrationEventType $type, string $summary): void
    {
        $summary = mb_substr(trim($summary), 0, self::SUMMARY_LIMIT);

        IntegrationEvent::query()->create([
            'server_id' => $server->id,
            'type' => $type,
            'summary' => $summary,
        ]);

        $key = $type->failuresKey($server);
        Cache::add($key, 0, self::COUNTER_TTL_SECONDS);
        $failures = (int) Cache::increment($key);

        if ($failures < self::THRESHOLD) {
            return;
        }

        $this->openAlert->handle(
            AlertType::IntegrationFailing,
            'admin.alerts.integrationFailing',
            ['server' => $server->name, 'count' => $failures],
            $server,
            $server->id,
        );

        if (Cache::add($type->mailLockKey($server), true, self::MAIL_INTERVAL_SECONDS)) {
            $this->notifyAdmins($server, $type, $summary, $failures);
        }
    }

    private function notifyAdmins(Server $server, IntegrationEventType $type, string $summary, int $failures): void
    {
        User::query()
            ->where('is_admin', true)
            ->whereNotNull('email')
            ->each(fn (User $admin) => Mail::to($admin)->send(new IntegrationFailingMail($server, $type, $summary, $failures)));
    }
}
