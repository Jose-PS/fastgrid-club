<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Models\ServerOverride;

final readonly class ServerOverrideResult
{
    public const APPLIED = 'applied';

    public const FAILED = 'failed';

    public const BUSY = 'busy';

    public function __construct(
        public string $status,
        public ?string $error = null,
        public ?ServerOverride $override = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === self::APPLIED;
    }
}
