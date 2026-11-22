<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Audit\RecordAudit;
use App\Actions\Proposals\ProposalListing;
use App\Enums\TrackMode;
use App\Models\Car;
use App\Models\Track;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SetProposable
{
    public function __construct(
        private readonly RecordAudit $recordAudit,
        private readonly ProposalListing $listing,
    ) {}

    public function handle(User $admin, Track|Car $item, bool $proposable): void
    {
        $before = $item->proposable;

        if ($before === $proposable) {
            return;
        }

        if ($proposable && $item instanceof Track && $item->mode === TrackMode::RaceWeekend && $item->paired_track_id === null) {
            throw ValidationException::withMessages(['proposable' => __('admin.catalog.errNoPractice')]);
        }

        $item->update(['proposable' => $proposable]);

        $this->recordAudit->handle(
            $admin,
            $item instanceof Track ? 'catalog.track_proposable' : 'catalog.car_proposable',
            $item,
            ['proposable' => $before],
            ['proposable' => $proposable],
        );

        $this->listing->forgetCatalog();
    }
}
