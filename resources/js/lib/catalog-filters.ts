import { normalizeSearch } from '@/lib/search';
import type { CatalogCar, CatalogTrack } from '@/types/proposals';

export type CarSort = 'name-asc' | 'name-desc' | 'pi-asc' | 'pi-desc';

export type TrackSort = 'name-asc' | 'name-desc' | 'length-asc' | 'length-desc';

export type CarFilters = {
    query: string;
    brands: string[];
    piMin: string;
    piMax: string;
    types: string[];
    eras: string[];
    engines: string[];
    classes: string[];
    sort: CarSort;
};

export type TrackFilters = {
    query: string;
    countries: string[];
    lengthMin: string;
    lengthMax: string;
    pitsMin: string;
    sort: TrackSort;
};

export const DEFAULT_CAR_FILTERS: CarFilters = {
    query: '',
    brands: [],
    piMin: '',
    piMax: '',
    types: [],
    eras: [],
    engines: [],
    classes: [],
    sort: 'name-asc',
};

export const DEFAULT_TRACK_FILTERS: TrackFilters = {
    query: '',
    countries: [],
    lengthMin: '',
    lengthMax: '',
    pitsMin: '',
    sort: 'name-asc',
};

export function parseBound(value: string): number | null {
    const text = value.trim().replace(',', '.');

    if (text === '') {
        return null;
    }

    const number = Number(text);

    return Number.isFinite(number) ? number : null;
}

function matchesQuery(text: string, query: string): boolean {
    const needle = normalizeSearch(query);

    return needle === '' || normalizeSearch(text).includes(needle);
}

function matchesAny(selected: string[], value: string | null): boolean {
    return (
        selected.length === 0 || (value !== null && selected.includes(value))
    );
}

function matchesRange(
    value: number | null,
    min: number | null,
    max: number | null,
): boolean {
    if (min === null && max === null) {
        return true;
    }

    if (value === null) {
        return false;
    }

    return (min === null || value >= min) && (max === null || value <= max);
}

function compareNames(a: string, b: string): number {
    return a.localeCompare(b, undefined, { sensitivity: 'base' });
}

function compareNumbers(
    a: number | null,
    b: number | null,
    direction: 1 | -1,
): number {
    if (a === null && b === null) {
        return 0;
    }

    if (a === null) {
        return 1;
    }

    if (b === null) {
        return -1;
    }

    return (a - b) * direction;
}

export function filterCars(
    cars: CatalogCar[],
    filters: CarFilters,
): CatalogCar[] {
    const piMin = parseBound(filters.piMin);
    const piMax = parseBound(filters.piMax);

    return cars.filter(
        (car) =>
            matchesQuery(car.displayName, filters.query) &&
            matchesAny(filters.brands, car.brand) &&
            matchesRange(car.pi, piMin, piMax) &&
            matchesAny(filters.types, car.type) &&
            matchesAny(filters.eras, car.era) &&
            matchesAny(filters.engines, car.engine) &&
            (filters.classes.length === 0 ||
                car.classes.some((value) => filters.classes.includes(value))),
    );
}

export function sortCars(cars: CatalogCar[], sort: CarSort): CatalogCar[] {
    const sorted = [...cars];

    switch (sort) {
        case 'name-desc':
            return sorted.sort((a, b) =>
                compareNames(b.displayName, a.displayName),
            );
        case 'pi-asc':
            return sorted.sort(
                (a, b) =>
                    compareNumbers(a.pi, b.pi, 1) ||
                    compareNames(a.displayName, b.displayName),
            );
        case 'pi-desc':
            return sorted.sort(
                (a, b) =>
                    compareNumbers(a.pi, b.pi, -1) ||
                    compareNames(a.displayName, b.displayName),
            );
        default:
            return sorted.sort((a, b) =>
                compareNames(a.displayName, b.displayName),
            );
    }
}

export function filterTracks(
    tracks: CatalogTrack[],
    filters: TrackFilters,
): CatalogTrack[] {
    const lengthMin = parseBound(filters.lengthMin);
    const lengthMax = parseBound(filters.lengthMax);
    const pitsMin = parseBound(filters.pitsMin);

    return tracks.filter(
        (track) =>
            matchesQuery(track.display, filters.query) &&
            matchesAny(filters.countries, track.country) &&
            matchesRange(
                track.lengthM === null ? null : track.lengthM / 1000,
                lengthMin,
                lengthMax,
            ) &&
            (pitsMin === null || track.maxPitSlot >= pitsMin),
    );
}

export function sortTracks(
    tracks: CatalogTrack[],
    sort: TrackSort,
): CatalogTrack[] {
    const sorted = [...tracks];

    switch (sort) {
        case 'name-desc':
            return sorted.sort((a, b) => compareNames(b.display, a.display));
        case 'length-asc':
            return sorted.sort(
                (a, b) =>
                    compareNumbers(a.lengthM, b.lengthM, 1) ||
                    compareNames(a.display, b.display),
            );
        case 'length-desc':
            return sorted.sort(
                (a, b) =>
                    compareNumbers(a.lengthM, b.lengthM, -1) ||
                    compareNames(a.display, b.display),
            );
        default:
            return sorted.sort((a, b) => compareNames(a.display, b.display));
    }
}

export function applyCarFilters(
    cars: CatalogCar[],
    filters: CarFilters,
): CatalogCar[] {
    return sortCars(filterCars(cars, filters), filters.sort);
}

export function applyTrackFilters(
    tracks: CatalogTrack[],
    filters: TrackFilters,
): CatalogTrack[] {
    return sortTracks(filterTracks(tracks, filters), filters.sort);
}

export function hasActiveCarFilters(filters: CarFilters): boolean {
    return (
        JSON.stringify({ ...filters, sort: 'name-asc' }) !==
        JSON.stringify(DEFAULT_CAR_FILTERS)
    );
}

export function hasActiveTrackFilters(filters: TrackFilters): boolean {
    return (
        JSON.stringify({ ...filters, sort: 'name-asc' }) !==
        JSON.stringify(DEFAULT_TRACK_FILTERS)
    );
}

export function toggleValue(selected: string[], value: string): string[] {
    return selected.includes(value)
        ? selected.filter((item) => item !== value)
        : [...selected, value];
}

const VALUE_LABEL_KEYS: Record<string, Record<string, string>> = {
    type: {
        road: 'props.typeRoad',
        race: 'props.typeRace',
        track: 'props.typeTrack',
    },
    era: {
        modern: 'props.eraModern',
        vintage: 'props.eraVintage',
        yt: 'props.eraYt',
    },
    engine: {
        ice: 'props.engineIce',
        ev: 'props.engineEv',
        hybrid: 'props.engineHybrid',
    },
};

const CLASS_LABELS: Record<string, string> = {
    f1: 'F1',
    gt2: 'GT2',
    gt3: 'GT3',
    gt4: 'GT4',
    cup: 'Cup',
};

export function valueLabelKey(
    group: 'type' | 'era' | 'engine',
    value: string,
): string | null {
    return VALUE_LABEL_KEYS[group][value] ?? null;
}

export function classLabel(value: string): string {
    return (
        CLASS_LABELS[value] ?? value.charAt(0).toUpperCase() + value.slice(1)
    );
}

export function countryName(code: string, locale: string): string {
    try {
        return (
            new Intl.DisplayNames([locale], { type: 'region' }).of(code) ?? code
        );
    } catch {
        return code;
    }
}

export function sortCountries(codes: string[], locale: string): string[] {
    return [...codes].sort((a, b) =>
        compareNames(countryName(a, locale), countryName(b, locale)),
    );
}
