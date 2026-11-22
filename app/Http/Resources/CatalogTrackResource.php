<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Track;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Track
 */
class CatalogTrackResource extends JsonResource
{
    /**
     * @return array{id: int, display: string, track: string, layout: string, country: string|null, lengthM: int|null, maxPitSlot: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display' => $this->display,
            'track' => $this->track,
            'layout' => $this->layout,
            'country' => $this->country,
            'lengthM' => $this->length_m,
            'maxPitSlot' => $this->max_pit_slot,
        ];
    }
}
