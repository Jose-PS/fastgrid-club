import { Check } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { CarLabel } from '@/components/car-label';
import { useT } from '@/hooks/use-t';
import {
    applyCarFilters,
    classLabel,
    DEFAULT_CAR_FILTERS,
    hasActiveCarFilters,
    toggleValue,
    valueLabelKey,
} from '@/lib/catalog-filters';
import type { CarFilters, CarSort } from '@/lib/catalog-filters';
import { CARS_PER_PAGE, paginate } from '@/lib/pagination';
import { toggleCar } from '@/lib/proposal-form';
import { cn } from '@/lib/utils';
import type { Catalog, CatalogCar } from '@/types/proposals';
import { primaryButtonClass, secondaryButtonClass } from '../account-styles';
import { Pagination } from './pagination';
import {
    ChipGroup,
    FilterPanel,
    RangeFields,
    SearchField,
    SortField,
} from './filter-fields';

const BADGE_CLASS =
    'inline-flex items-center rounded-sm border border-border px-2 py-0.5 text-xs text-muted';

function CarCard({
    car,
    selected,
    selectable,
    onToggle,
}: {
    car: CatalogCar;
    selected: boolean;
    selectable: boolean;
    onToggle: () => void;
}) {
    const t = useT();
    const attributes: ['type' | 'era' | 'engine', string | null][] = [
        ['type', car.type],
        ['era', car.era],
        ['engine', car.engine],
    ];

    return (
        <li
            className={cn(
                'grid content-between gap-3 rounded-md border border-border bg-surface p-4 transition-colors duration-150 ease-out',
                selected && 'border-primary bg-tint',
            )}
        >
            <div className="grid gap-2">
                <h4 className="font-display text-base leading-tight font-bold">
                    <CarLabel name={car.displayName} />
                </h4>
                {car.brand ? (
                    <p className="text-sm text-muted">{car.brand}</p>
                ) : null}
                <div className="flex flex-wrap gap-1.5">
                    {car.pi !== null ? (
                        <span className={cn(BADGE_CLASS, 'mono text-fg')}>
                            {t('props.pi', { value: car.pi.toFixed(1) })}
                        </span>
                    ) : null}
                    {attributes.map(([group, value]) => {
                        if (value === null) {
                            return null;
                        }

                        const key = valueLabelKey(group, value);

                        return (
                            <span key={group} className={BADGE_CLASS}>
                                {key ? t(key) : value}
                            </span>
                        );
                    })}
                    {car.classes.map((value) => (
                        <span key={value} className={BADGE_CLASS}>
                            {classLabel(value)}
                        </span>
                    ))}
                </div>
            </div>
            {selectable ? (
                <button
                    type="button"
                    aria-pressed={selected}
                    aria-label={t('props.chooseItem', {
                        name: car.displayName,
                    })}
                    onClick={onToggle}
                    className={cn(
                        selected ? primaryButtonClass : secondaryButtonClass,
                        'justify-self-start',
                    )}
                >
                    {selected ? (
                        <Check className="size-4" aria-hidden="true" />
                    ) : null}
                    {selected ? t('props.chosen') : t('props.choose')}
                </button>
            ) : null}
        </li>
    );
}

export function CarPicker({
    catalog,
    selectedIds,
    selectable,
    onChange,
}: {
    catalog: Catalog;
    selectedIds: number[];
    selectable: boolean;
    onChange: (carIds: number[]) => void;
}) {
    const t = useT();
    const [filters, setFilters] = useState<CarFilters>(DEFAULT_CAR_FILTERS);
    const results = useMemo(
        () => applyCarFilters(catalog.cars, filters),
        [catalog.cars, filters],
    );
    const [pageNumber, setPageNumber] = useState(1);
    const gridRef = useRef<HTMLDivElement>(null);
    const current = paginate(results, pageNumber, CARS_PER_PAGE);
    const patch = (changes: Partial<CarFilters>): void => {
        setFilters((previous) => ({ ...previous, ...changes }));
        setPageNumber(1);
    };
    const goToPage = (next: number): void => {
        setPageNumber(next);
        requestAnimationFrame(() => gridRef.current?.focus());
    };
    const sortOptions: { value: CarSort; label: string }[] = [
        { value: 'name-asc', label: t('props.sortNameAsc') },
        { value: 'name-desc', label: t('props.sortNameDesc') },
        { value: 'pi-asc', label: t('props.sortPiAsc') },
        { value: 'pi-desc', label: t('props.sortPiDesc') },
    ];
    const labelFor =
        (group: 'type' | 'era' | 'engine') =>
        (value: string): string => {
            const key = valueLabelKey(group, value);

            return key ? t(key) : value;
        };

    return (
        <div className="grid gap-4">
            <p aria-live="polite" className="mono text-[13px] text-muted">
                {t('props.resultsCars', { n: results.length })}
                {selectable ? ` · ${selectedIds.length}` : ''}
            </p>
            <div className="grid gap-5 lg:grid-cols-[280px_minmax(0,1fr)] lg:items-start">
                <FilterPanel
                    id="car-filters"
                    canClear={hasActiveCarFilters(filters)}
                    onClear={() =>
                        patch({ ...DEFAULT_CAR_FILTERS, sort: filters.sort })
                    }
                >
                    <ChipGroup
                        legend={t('props.brand')}
                        values={catalog.facets.brands}
                        selected={filters.brands}
                        label={(value) => value}
                        scrollable
                        onToggle={(value) =>
                            patch({
                                brands: toggleValue(filters.brands, value),
                            })
                        }
                    />
                    <RangeFields
                        idPrefix="car-pi"
                        legend={t('props.piRange')}
                        minValue={filters.piMin}
                        maxValue={filters.piMax}
                        minPlaceholder={String(catalog.ranges.pi.min)}
                        maxPlaceholder={String(catalog.ranges.pi.max)}
                        step="0.1"
                        onMinChange={(value) => patch({ piMin: value })}
                        onMaxChange={(value) => patch({ piMax: value })}
                    />
                    <ChipGroup
                        legend={t('props.type')}
                        values={catalog.facets.types}
                        selected={filters.types}
                        label={labelFor('type')}
                        onToggle={(value) =>
                            patch({ types: toggleValue(filters.types, value) })
                        }
                    />
                    <ChipGroup
                        legend={t('props.era')}
                        values={catalog.facets.eras}
                        selected={filters.eras}
                        label={labelFor('era')}
                        onToggle={(value) =>
                            patch({ eras: toggleValue(filters.eras, value) })
                        }
                    />
                    <ChipGroup
                        legend={t('props.engine')}
                        values={catalog.facets.engines}
                        selected={filters.engines}
                        label={labelFor('engine')}
                        onToggle={(value) =>
                            patch({
                                engines: toggleValue(filters.engines, value),
                            })
                        }
                    />
                    <ChipGroup
                        legend={t('props.class')}
                        values={catalog.facets.classes}
                        selected={filters.classes}
                        label={classLabel}
                        onToggle={(value) =>
                            patch({
                                classes: toggleValue(filters.classes, value),
                            })
                        }
                    />
                </FilterPanel>
                <div className="grid gap-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <SearchField
                            id="car-search"
                            label={t('props.searchName')}
                            value={filters.query}
                            onChange={(value) => patch({ query: value })}
                        />
                        <SortField
                            id="car-sort"
                            value={filters.sort}
                            options={sortOptions}
                            onChange={(value) =>
                                patch({ sort: value as CarSort })
                            }
                        />
                    </div>
                    <div
                        ref={gridRef}
                        tabIndex={-1}
                        className="grid scroll-mt-44 gap-4 outline-none"
                    >
                        {results.length === 0 ? (
                            <p className="text-muted">{t('props.noMatches')}</p>
                        ) : (
                            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {current.items.map((car) => {
                                    const selected = selectedIds.includes(
                                        car.id,
                                    );

                                    return (
                                        <CarCard
                                            key={car.id}
                                            car={car}
                                            selected={selected}
                                            selectable={selectable}
                                            onToggle={() =>
                                                onChange(
                                                    toggleCar(
                                                        selectedIds,
                                                        car.id,
                                                    ),
                                                )
                                            }
                                        />
                                    );
                                })}
                            </ul>
                        )}
                        {current.total > 0 ? (
                            <p className="text-center mono text-[13px] text-muted">
                                {t('props.showing', {
                                    from: current.from,
                                    to: current.to,
                                    total: current.total,
                                })}
                            </p>
                        ) : null}
                        <Pagination page={current} onChange={goToPage} />
                    </div>
                </div>
            </div>
        </div>
    );
}
