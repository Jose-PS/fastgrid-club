<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Integration\RecordIntegrationFailure;
use App\Actions\Integration\RecordIntegrationSuccess;
use App\Enums\IntegrationEventType;
use App\Integration\Server\ServerDriverException;
use App\Integration\Server\ServerDriverManager;
use App\Models\CatalogSync;
use App\Models\Server;
use App\Models\User;
use Throwable;

class RunCatalogSync
{
    public const UNEXPECTED_FAILURE = 'unexpected error while syncing the catalog';

    public function __construct(
        private readonly ServerDriverManager $drivers,
        private readonly SyncCatalog $syncCatalog,
        private readonly RecordIntegrationFailure $recordFailure,
        private readonly RecordIntegrationSuccess $recordSuccess,
    ) {}

    /**
     * @throws ServerDriverException
     */
    public function handle(Server $server, ?User $actor = null): CatalogSync
    {
        try {
            $sync = $this->syncCatalog->handle($server, $this->drivers->for($server)->catalog(), null, $actor);
        } catch (ServerDriverException $exception) {
            $this->recordFailure->handle($server, IntegrationEventType::SyncFailed, $exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->recordFailure->handle($server, IntegrationEventType::SyncFailed, self::UNEXPECTED_FAILURE);

            throw new ServerDriverException(self::UNEXPECTED_FAILURE, previous: $exception);
        }

        $this->recordSuccess->handle($server, IntegrationEventType::SyncFailed);

        return $sync;
    }
}
