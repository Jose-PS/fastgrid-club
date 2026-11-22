<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Car;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Car
 */
class CatalogCarResource extends JsonResource
{
    /**
     * @return array{id: int, displayName: string, brand: string|null, pi: float|null, type: string|null, era: string|null, engine: string|null, classes: array<int, string>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'displayName' => $this->display_name,
            'brand' => $this->brand,
            'pi' => $this->pi === null ? null : (float) $this->pi,
            'type' => $this->type,
            'era' => $this->era,
            'engine' => $this->engine,
            'classes' => array_values(array_unique(array_map(
                fn (string $class): string => mb_strtolower($class),
                $this->classes,
            ))),
        ];
    }
}
