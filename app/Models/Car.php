<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $internal_name
 * @property string $display_name
 * @property string|null $type
 * @property string|null $era
 * @property string|null $engine
 * @property string|null $pi
 * @property string|null $brand
 * @property string|null $runtime_name
 * @property array<int, string> $classes
 * @property bool $is_mod
 * @property bool $active
 * @property bool $proposable
 * @property CarbonInterface|null $synced_at
 */
#[Fillable(['internal_name', 'display_name', 'type', 'era', 'engine', 'pi', 'brand', 'runtime_name', 'classes', 'is_mod', 'active', 'proposable', 'synced_at'])]
class Car extends Model
{
    /** @use HasFactory<CarFactory> */
    use HasFactory;

    public const MULTI_WORD_BRANDS = ['Alfa Romeo', 'Aston Martin', 'Land Rover', 'Rolls-Royce'];

    public static function brandFromDisplayName(string $displayName): string
    {
        foreach (self::MULTI_WORD_BRANDS as $brand) {
            if (str_starts_with($displayName, $brand.' ')) {
                return $brand;
            }
        }

        return explode(' ', $displayName)[0];
    }

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pi' => 'decimal:1',
            'classes' => 'array',
            'is_mod' => 'boolean',
            'active' => 'boolean',
            'proposable' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Car>  $query
     */
    #[Scope]
    protected function proposable(Builder $query): void
    {
        $query->where('active', true)
            ->where('proposable', true)
            ->where('is_mod', false);
    }
}
