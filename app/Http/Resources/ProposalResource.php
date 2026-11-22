<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Car;
use App\Models\Proposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Proposal
 */
class ProposalResource extends JsonResource
{
    /**
     * @return array{id: int, rank: int, track: array{display: string, maxPitSlot: int}, cars: array<int, array{id: int, displayName: string}>, author: string|null, votes: int, createdAt: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rank' => (int) $this->getAttribute('rank'),
            'track' => [
                'display' => $this->track->display,
                'maxPitSlot' => $this->track->max_pit_slot,
            ],
            'cars' => $this->cars->map(fn (Car $car): array => [
                'id' => $car->id,
                'displayName' => $car->display_name,
            ])->values()->all(),
            'author' => $this->user?->name,
            'votes' => (int) $this->votes_count,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
