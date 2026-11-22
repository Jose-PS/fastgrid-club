import { usePage } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useT } from '@/hooks/use-t';
import {
    applyTrackFilters,
    countryName,
    DEFAULT_TRACK_FILTERS,
    hasActiveTrackFilters,
    sortCountries,
    toggleValue,
} from '@/lib/catalog-filters';
import type { TrackFilters, TrackSort } from '@/lib/catalog-filters';
import { cn } from '@/lib/utils';
import type { Catalog, CatalogTrack } from '@/types/proposals';
import { primaryButtonClass, secondaryButtonClass } from '../account-styles';
import {
    ChipGroup,
    FilterPanel,
    NumberField,
    RangeFields,
    SearchField,
    SortField,
} from './filter-fields';

function TrackRow({
    track,
    selected,
    selectable,
    kmFormat,
    locale,
    onToggle,
}: {
    track: CatalogTrack;
    selected: boolean;
    selectable: boolean;
    kmFormat: Intl.NumberFormat;
    locale: string;
    onToggle: () => void;
}) {
    const t = useT();
    const details = [
        track.country ? countryName(track.country, locale) : null,
        track.lengthM !== null
            ? t('props.km', { n: kmFormat.format(track.lengthM / 1000) })
            : null,
        t('props.pits', { n: track.maxPitSlot }),
    ].filter((item): item is string => item !== null);

    return (
        <li
            className={cn(
                'flex flex-wrap items-center justify-between gap-x-4 gap-y-3 rounded-md border border-border bg-surface p-4 transition-colors duration-150 ease-out',
                selected && 'border-primary bg-tint',
            )}
        >
            <div className="grid gap-1">
                <h4 className="font-display text-base leading-tight font-bold">
                    {track.display}
                </h4>
                <p className="mono text-[13px] text-muted">
                    {details.join(' · ')}
                </p>
            </div>
            {selectable ? (
                <button
                    type="button"
                    aria-pressed={selected}
                    aria-label={t('props.chooseItem', { name: track.display })}
                    onClick={onToggle}
                    className={
                        selected ? primaryButtonClass : secondaryButtonClass
                    }
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

export function TrackPicker({
    catalog,
    selectedId,
    selectable,
    onChange,
    onNext,
}: {
    catalog: Catalog;
    selectedId: number | null;
    selectable: boolean;
    onChange: (trackId: number | null) => void;
    onNext: () => void;
}) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const kmFormat = useMemo(
        () =>
            new Intl.NumberFormat(locale, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }),
        [locale],
    );
    const countries = useMemo(
        () => sortCountries(catalog.facets.countries, locale),
        [catalog.facets.countries, locale],
    );
    const [filters, setFilters] = useState<TrackFilters>(DEFAULT_TRACK_FILTERS);
    const results = useMemo(
        () => applyTrackFilters(catalog.tracks, filters),
        [catalog.tracks, filters],
    );
    const patch = (changes: Partial<TrackFilters>): void =>
        setFilters((current) => ({ ...current, ...changes }));
    const sortOptions: { value: TrackSort; label: string }[] = [
        { value: 'name-asc', label: t('props.sortNameAsc') },
        { value: 'name-desc', label: t('props.sortNameDesc') },
        { value: 'length-asc', label: t('props.sortLengthAsc') },
        { value: 'length-desc', label: t('props.sortLengthDesc') },
    ];
    const lengthKm = (meters: number): string => String(meters / 1000);

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p aria-live="polite" className="mono text-[13px] text-muted">
                    {t('props.resultsTracks', { n: results.length })}
                </p>
                {selectable && selectedId !== null ? (
                    <button
                        type="button"
                        onClick={onNext}
                        className={primaryButtonClass}
                    >
                        {t('props.nextCars')}
                        <ArrowRight className="size-4" aria-hidden="true" />
                    </button>
                ) : null}
            </div>
            <div className="grid gap-5 lg:grid-cols-[280px_minmax(0,1fr)] lg:items-start">
                <FilterPanel
                    id="track-filters"
                    canClear={hasActiveTrackFilters(filters)}
                    onClear={() =>
                        patch({ ...DEFAULT_TRACK_FILTERS, sort: filters.sort })
                    }
                >
                    <ChipGroup
                        legend={t('props.country')}
                        values={countries}
                        selected={filters.countries}
                        label={(value) => countryName(value, locale)}
                        onToggle={(value) =>
                            patch({
                                countries: toggleValue(
                                    filters.countries,
                                    value,
                                ),
                            })
                        }
                    />
                    <RangeFields
                        idPrefix="track-length"
                        legend={t('props.length')}
                        minValue={filters.lengthMin}
                        maxValue={filters.lengthMax}
                        minPlaceholder={lengthKm(catalog.ranges.lengthM.min)}
                        maxPlaceholder={lengthKm(catalog.ranges.lengthM.max)}
                        step="0.1"
                        onMinChange={(value) => patch({ lengthMin: value })}
                        onMaxChange={(value) => patch({ lengthMax: value })}
                    />
                    <NumberField
                        id="track-pits"
                        label={t('props.minPits')}
                        value={filters.pitsMin}
                        placeholder={String(catalog.ranges.pitSlots.min)}
                        onChange={(value) => patch({ pitsMin: value })}
                    />
                </FilterPanel>
                <div className="grid gap-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <SearchField
                            id="track-search"
                            label={t('props.searchName')}
                            value={filters.query}
                            onChange={(value) => patch({ query: value })}
                        />
                        <SortField
                            id="track-sort"
                            value={filters.sort}
                            options={sortOptions}
                            onChange={(value) =>
                                patch({ sort: value as TrackSort })
                            }
                        />
                    </div>
                    {results.length === 0 ? (
                        <p className="text-muted">{t('props.noMatches')}</p>
                    ) : (
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {results.map((track) => {
                                const selected = selectedId === track.id;

                                return (
                                    <TrackRow
                                        key={track.id}
                                        track={track}
                                        selected={selected}
                                        selectable={selectable}
                                        kmFormat={kmFormat}
                                        locale={locale}
                                        onToggle={() =>
                                            onChange(selected ? null : track.id)
                                        }
                                    />
                                );
                            })}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}
