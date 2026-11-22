<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Donations\CurrentDonationPeriod;
use App\Actions\Donations\UpdateDonations;
use App\Enums\DonationPlatform;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDonationsRequest;
use App\Models\DonationMethod;
use App\Models\DonationPeriod;
use App\Models\User;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DonationsController extends Controller
{
    public function show(Translations $translations, CurrentDonationPeriod $currentPeriod): Response
    {
        $current = $currentPeriod->handle();
        $urls = DonationMethod::query()->pluck('url', 'platform');

        return Inertia::render('admin/donations', [
            'pageTranslations' => $translations->forGroups(['admin']),
            'methods' => array_map(fn (DonationPlatform $platform): array => [
                'platform' => $platform->value,
                'url' => $urls[$platform->value] ?? null,
                'domain' => $platform->domain(),
            ], DonationPlatform::cases()),
            'period' => $this->serialize($current),
            'history' => DonationPeriod::query()
                ->orderByDesc('month')
                ->get()
                ->map(fn (DonationPeriod $period): array => $this->serialize($period))
                ->all(),
        ]);
    }

    public function update(UpdateDonationsRequest $request, UpdateDonations $updateDonations): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $updateDonations->handle(
            $user,
            $request->validated('urls', []),
            $request->integer('goal_cents'),
            $request->integer('covered_cents'),
        );

        return back();
    }

    /**
     * @return array{id: int, month: string, goal_cents: int, covered_cents: int, percent: int, updated_at: string|null}
     */
    private function serialize(DonationPeriod $period): array
    {
        return [
            'id' => $period->id,
            'month' => $period->month->format('Y-m'),
            'goal_cents' => $period->goal_cents,
            'covered_cents' => $period->covered_cents,
            'percent' => $period->percent(),
            'updated_at' => $period->updated_at?->toIso8601String(),
        ];
    }
}
