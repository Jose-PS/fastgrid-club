<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Alerts\CloseAlert;
use App\Actions\Alerts\OpenAlert;
use App\Enums\AlertType;
use App\Models\CatalogSync;
use App\Models\Server;
use Carbon\CarbonImmutable;

class CheckCatalogFreshness
{
    public const STALE_AFTER_HOURS = 48;

    public function __construct(
        private readonly OpenAlert $openAlert,
        private readonly CloseAlert $closeAlert,
    ) {}

    public function handle(Server $server, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        $last = CatalogSync::query()->where('server_id', $server->id)->max('finished_at');
        $reference = $last === null ? CarbonImmutable::instance($server->created_at ?? $now) : CarbonImmutable::parse($last);

        if ($reference->addHours(self::STALE_AFTER_HOURS)->greaterThan($now)) {
            $this->closeAlert->forSubject(AlertType::CatalogStale, $server);

            return false;
        }

        $this->openAlert->handle(
            AlertType::CatalogStale,
            'admin.alerts.catalogStale',
            ['server' => $server->name, 'hours' => self::STALE_AFTER_HOURS],
            $server,
            $server->id,
        );

        return true;
    }
}
