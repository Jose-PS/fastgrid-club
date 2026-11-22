<?php

declare(strict_types=1);

namespace App\Integration\Server;

use App\Integration\Server\Data\Catalog;
use App\Integration\Server\Data\ConfigPlan;
use App\Integration\Server\Data\ConfigSnapshot;
use App\Integration\Server\Data\EditableConfig;
use App\Integration\Server\Data\LiveSnapshot;
use App\Integration\Server\Data\LogEvents;
use App\Integration\Server\Data\LogTail;
use App\Integration\Server\Data\OperationResult;
use App\Integration\Server\Data\ServerSecrets;
use App\Integration\Server\Data\ServerStatusReport;

interface ServerDriver
{
    public function status(): ServerStatusReport;

    public function live(): LiveSnapshot;

    public function logTail(int $lines): LogTail;

    public function logEvents(int $afterId, int $limit): LogEvents;

    public function catalog(): Catalog;

    public function currentConfig(): ConfigSnapshot;

    public function editableConfig(): EditableConfig;

    public function apply(ConfigPlan $plan, ?ServerSecrets $secrets = null): OperationResult;

    public function start(): OperationResult;
}
