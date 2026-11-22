<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordAudit
{
    private const SENSITIVE_FRAGMENTS = ['password', 'token', 'secret', 'credential', 'remember'];

    /**
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     */
    public function handle(?User $actor, string $action, ?Model $subject = null, ?array $before = null, ?array $after = null): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before === null ? null : $this->sanitize($before),
            'after' => $after === null ? null : $this->sanitize($after),
            'ip' => request()->route() === null ? null : request()->ip(),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function sanitize(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } elseif (is_string($value)) {
                $clean[$key] = $this->maskEmails($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
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

    private function maskEmails(string $value): string
    {
        return (string) preg_replace_callback(
            '/([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/',
            fn (array $match): string => $match[1].'***@'.$match[2],
            $value,
        );
    }
}
