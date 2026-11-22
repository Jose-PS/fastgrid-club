<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

final readonly class MandatoryPitstop
{
    public function __construct(
        public bool $enabled,
        public int $windowSeconds,
        public bool $refuel,
        public bool $tyreChange,
    ) {}

    /**
     * @return array<string, bool|int>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'refuel' => $this->refuel,
            'tyre_change' => $this->tyreChange,
            'window_seconds' => $this->windowSeconds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['enabled'] ?? false),
            (int) ($data['window_seconds'] ?? 600),
            (bool) ($data['refuel'] ?? true),
            (bool) ($data['tyre_change'] ?? true),
        );
    }
}
