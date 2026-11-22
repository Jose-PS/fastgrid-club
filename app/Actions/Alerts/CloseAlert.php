<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Actions\Audit\RecordAudit;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CloseAlert
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    public function handle(Alert $alert, User $admin): Alert
    {
        if ($alert->status === AlertStatus::Closed) {
            return $alert;
        }

        $alert->update(['status' => AlertStatus::Closed, 'closed_at' => now()]);

        $this->recordAudit->handle(
            $admin,
            'alert.closed',
            $alert,
            ['status' => AlertStatus::Open->value],
            ['status' => AlertStatus::Closed->value],
        );

        return $alert;
    }

    public function forSubject(AlertType $type, Model $subject): int
    {
        return Alert::query()
            ->open()
            ->where('type', $type)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->update(['status' => AlertStatus::Closed, 'closed_at' => now(), 'updated_at' => now()]);
    }

    public function forType(AlertType $type): int
    {
        return Alert::query()
            ->open()
            ->where('type', $type)
            ->whereNull('subject_type')
            ->update(['status' => AlertStatus::Closed, 'closed_at' => now(), 'updated_at' => now()]);
    }
}
