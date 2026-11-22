<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class MaskSensitiveData implements ProcessorInterface
{
    private const MASK = '[masked]';

    private const SENSITIVE_FRAGMENTS = ['password', 'authorization', 'token', 'secret', 'cookie', 'mail', 'steam_id', 'steamid'];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->mask($record->context),
            extra: $this->mask($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = self::MASK;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->mask($value);
            }
        }

        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
