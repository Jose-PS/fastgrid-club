import { describe, expect, it } from 'vitest';
import {
    applyCarFilters,
    applyTrackFilters,
    classLabel,
    countryName,
    DEFAULT_CAR_FILTERS,
    DEFAULT_TRACK_FILTERS,
    filterCars,
    filterTracks,
    hasActiveCarFilters,
    hasActiveTrackFilters,
    parseBound,
    sortCars,
    sortCountries,
    sortTracks,
    toggleValue,
    valueLabelKey,
} from './catalog-filters';
import type { CatalogCar, CatalogTrack } from '@/types/proposals';

function car(id: number, overrides: Partial<CatalogCar> = {}): CatalogCar {
    return {
        id,
        displayName: `Car ${id}`,
        brand: 'Ferrari',
        pi: 20,
        type: 'road',
        era: 'modern',
        engine: 'ice',
        classes: [],
        ...overrides,
    };
}

function track(
    id: number,
    overrides: Partial<CatalogTrack> = {},
): CatalogTrack {
    return {
        id,
        display: `Track ${id}`,
        track: `Track ${id}`,
        layout: 'GP',
        country: 'IT',
        lengthM: 5000,
        maxPitSlot: 24,
        ...overrides,
    };
}

const cars: CatalogCar[] = [
    car(1, {
        displayName: 'Porsche 911 GT3 R',
        brand: 'Porsche',
        pi: 30,
        type: 'race',
        classes: ['gt3'],
    }),
    car(2, {
        displayName: 'Ferrari 250 GTO',
        brand: 'Ferrari',
        pi: 12.5,
        type: 'road',
        era: 'vintage',
    }),
    car(3, {
        displayName: 'Rimac Nevera',
        brand: 'Rimac',
        pi: 40,
        engine: 'ev',
    }),
    car(4, {
        displayName: 'Ford GT',
        brand: 'Ford',
        pi: null,
        era: 'yt',
        engine: 'hybrid',
        classes: ['gt3', 'cup'],
    }),
];

const ids = (list: { id: number }[]): number[] => list.map((item) => item.id);

describe('parseBound', () => {
    it('returns null for empty or invalid text', () => {
        expect(parseBound('')).toBeNull();
        expect(parseBound('  ')).toBeNull();
        expect(parseBound('abc')).toBeNull();
    });

    it('accepts a comma as decimal separator', () => {
        expect(parseBound('7,5')).toBe(7.5);
        expect(parseBound('12')).toBe(12);
    });
});

describe('filterCars', () => {
    it('returns everything without filters', () => {
        expect(ids(filterCars(cars, DEFAULT_CAR_FILTERS))).toEqual([
            1, 2, 3, 4,
        ]);
    });

    it('searches the name ignoring accents and case', () => {
        expect(
            ids(filterCars(cars, { ...DEFAULT_CAR_FILTERS, query: 'PORSCHÉ' })),
        ).toEqual([1]);
    });

    it('filters by several brands', () => {
        expect(
            ids(
                filterCars(cars, {
                    ...DEFAULT_CAR_FILTERS,
                    brands: ['Ferrari', 'Ford'],
                }),
            ),
        ).toEqual([2, 4]);
    });

    it('filters by PI range and excludes unknown PI when a bound is set', () => {
        expect(
            ids(filterCars(cars, { ...DEFAULT_CAR_FILTERS, piMin: '12.5' })),
        ).toEqual([1, 2, 3]);
        expect(
            ids(filterCars(cars, { ...DEFAULT_CAR_FILTERS, piMax: '30' })),
        ).toEqual([1, 2]);
        expect(
            ids(
                filterCars(cars, {
                    ...DEFAULT_CAR_FILTERS,
                    piMin: '13',
                    piMax: '35',
                }),
            ),
        ).toEqual([1]);
    });

    it('filters by type, era and engine', () => {
        expect(
            ids(filterCars(cars, { ...DEFAULT_CAR_FILTERS, types: ['race'] })),
        ).toEqual([1]);
        expect(
            ids(
                filterCars(cars, {
                    ...DEFAULT_CAR_FILTERS,
                    eras: ['vintage', 'yt'],
                }),
            ),
        ).toEqual([2, 4]);
        expect(
            ids(
                filterCars(cars, {
                    ...DEFAULT_CAR_FILTERS,
                    engines: ['ev', 'hybrid'],
                }),
            ),
        ).toEqual([3, 4]);
    });

    it('filters by class matching any selected class', () => {
        expect(
            ids(filterCars(cars, { ...DEFAULT_CAR_FILTERS, classes: ['cup'] })),
        ).toEqual([4]);
        expect(
            ids(filterCars(cars, { ...DEFAULT_CAR_FILTERS, classes: ['gt3'] })),
        ).toEqual([1, 4]);
    });

    it('combines every criterion', () => {
        expect(
            ids(
                filterCars(cars, {
                    ...DEFAULT_CAR_FILTERS,
                    query: 'gt',
                    classes: ['gt3'],
                    types: ['race'],
                    piMin: '20',
                }),
            ),
        ).toEqual([1]);
        expect(
            ids(
                filterCars(cars, {
                    ...DEFAULT_CAR_FILTERS,
                    brands: ['Rimac'],
                    eras: ['vintage'],
                }),
            ),
        ).toEqual([]);
    });
});

describe('sortCars', () => {
    it('sorts by name in both directions', () => {
        expect(ids(sortCars(cars, 'name-asc'))).toEqual([2, 4, 1, 3]);
        expect(ids(sortCars(cars, 'name-desc'))).toEqual([3, 1, 4, 2]);
    });

    it('sorts by PI keeping unknown PI last', () => {
        expect(ids(sortCars(cars, 'pi-asc'))).toEqual([2, 1, 3, 4]);
        expect(ids(sortCars(cars, 'pi-desc'))).toEqual([3, 1, 2, 4]);
    });

    it('does not mutate the input', () => {
        const copy = [...cars];
        sortCars(cars, 'pi-desc');

        expect(cars).toEqual(copy);
    });
});

describe('applyCarFilters', () => {
    it('filters and sorts', () => {
        expect(
            ids(
                applyCarFilters(cars, {
                    ...DEFAULT_CAR_FILTERS,
                    piMin: '10',
                    sort: 'pi-desc',
                }),
            ),
        ).toEqual([3, 1, 2]);
    });
});

const tracks: CatalogTrack[] = [
    track(1, {
        display: 'Monza GP',
        country: 'IT',
        lengthM: 5793,
        maxPitSlot: 30,
    }),
    track(2, {
        display: 'Brands Hatch Indy',
        country: 'GB',
        lengthM: 1929,
        maxPitSlot: 10,
    }),
    track(3, {
        display: 'Nürburgring GP',
        country: 'DE',
        lengthM: 5148,
        maxPitSlot: 45,
    }),
    track(4, {
        display: 'Unknown',
        country: null,
        lengthM: null,
        maxPitSlot: 24,
    }),
];

describe('filterTracks', () => {
    it('returns everything without filters', () => {
        expect(ids(filterTracks(tracks, DEFAULT_TRACK_FILTERS))).toEqual([
            1, 2, 3, 4,
        ]);
    });

    it('searches by name ignoring accents', () => {
        expect(
            ids(
                filterTracks(tracks, {
                    ...DEFAULT_TRACK_FILTERS,
                    query: 'nurburg',
                }),
            ),
        ).toEqual([3]);
        expect(
            ids(
                filterTracks(tracks, {
                    ...DEFAULT_TRACK_FILTERS,
                    query: 'nürburg',
                }),
            ),
        ).toEqual([3]);
    });

    it('filters by length in kilometres', () => {
        expect(
            ids(
                filterTracks(tracks, {
                    ...DEFAULT_TRACK_FILTERS,
                    lengthMin: '5',
                }),
            ),
        ).toEqual([1, 3]);
        expect(
            ids(
                filterTracks(tracks, {
                    ...DEFAULT_TRACK_FILTERS,
                    lengthMax: '2',
                }),
            ),
        ).toEqual([2]);
        expect(
            ids(
                filterTracks(tracks, {
                    ...DEFAULT_TRACK_FILTERS,
                    lengthMin: '5,2',
                    lengthMax: '6',
                }),
            ),
        ).toEqual([1]);
    });

    it('filters by several countries and excludes tracks without country', () => {
        expect(
            ids(
                filterTracks(tracks, {
                    ...DEFAULT_TRACK_FILTERS,
                    countries: ['IT', 'DE'],
                }),
            ),
        ).toEqual([1, 3]);
        expect(
            ids(
                filterTracks(tracks, {
                    ...DEFAULT_TRACK_FILTERS,
                    countries: ['GB'],
                    pitsMin: '20',
                }),
            ),
        ).toEqual([]);
    });

    it('filters by minimum pit slots', () => {
        expect(
            ids(
                filterTracks(tracks, {
                    ...DEFAULT_TRACK_FILTERS,
                    pitsMin: '30',
                }),
            ),
        ).toEqual([1, 3]);
    });

    it('combines criteria', () => {
        expect(
            ids(
                filterTracks(tracks, {
                    ...DEFAULT_TRACK_FILTERS,
                    query: 'gp',
                    pitsMin: '40',
                    lengthMin: '5',
                }),
            ),
        ).toEqual([3]);
    });
});

describe('sortTracks', () => {
    it('sorts by name in both directions', () => {
        expect(ids(sortTracks(tracks, 'name-asc'))).toEqual([2, 1, 3, 4]);
        expect(ids(sortTracks(tracks, 'name-desc'))).toEqual([4, 3, 1, 2]);
    });

    it('sorts by length keeping unknown length last', () => {
        expect(ids(sortTracks(tracks, 'length-asc'))).toEqual([2, 3, 1, 4]);
        expect(ids(sortTracks(tracks, 'length-desc'))).toEqual([1, 3, 2, 4]);
    });
});

describe('applyTrackFilters', () => {
    it('filters and sorts', () => {
        expect(
            ids(
                applyTrackFilters(tracks, {
                    ...DEFAULT_TRACK_FILTERS,
                    pitsMin: '30',
                    sort: 'length-asc',
                }),
            ),
        ).toEqual([3, 1]);
    });
});

describe('active filters', () => {
    it('ignores the sort', () => {
        expect(
            hasActiveCarFilters({ ...DEFAULT_CAR_FILTERS, sort: 'pi-desc' }),
        ).toBe(false);
        expect(
            hasActiveTrackFilters({
                ...DEFAULT_TRACK_FILTERS,
                sort: 'length-desc',
            }),
        ).toBe(false);
    });

    it('detects any applied criterion', () => {
        expect(
            hasActiveCarFilters({ ...DEFAULT_CAR_FILTERS, piMin: '1' }),
        ).toBe(true);
        expect(
            hasActiveCarFilters({ ...DEFAULT_CAR_FILTERS, brands: ['Ford'] }),
        ).toBe(true);
        expect(
            hasActiveTrackFilters({ ...DEFAULT_TRACK_FILTERS, pitsMin: '1' }),
        ).toBe(true);
    });
});

describe('toggleValue and labels', () => {
    it('adds and removes values', () => {
        expect(toggleValue(['a'], 'b')).toEqual(['a', 'b']);
        expect(toggleValue(['a', 'b'], 'a')).toEqual(['b']);
    });

    it('maps known values to dictionary keys and unknown to null', () => {
        expect(valueLabelKey('type', 'road')).toBe('props.typeRoad');
        expect(valueLabelKey('era', 'yt')).toBe('props.eraYt');
        expect(valueLabelKey('engine', 'hybrid')).toBe('props.engineHybrid');
        expect(valueLabelKey('engine', 'V8')).toBeNull();
    });

    it('writes classes in upper case and falls back to capitalised', () => {
        expect(classLabel('gt3')).toBe('GT3');
        expect(classLabel('f1')).toBe('F1');
        expect(classLabel('cup')).toBe('Cup');
        expect(classLabel('touring')).toBe('Touring');
    });
});

describe('countries', () => {
    it('localises the country name', () => {
        expect(countryName('DE', 'en')).toBe('Germany');
        expect(countryName('DE', 'es')).toBe('Alemania');
    });

    it('orders codes by the localised name', () => {
        expect(sortCountries(['US', 'DE', 'GB', 'IT'], 'es')).toEqual([
            'DE',
            'US',
            'IT',
            'GB',
        ]);
        expect(sortCountries(['US', 'DE', 'GB', 'IT'], 'en')).toEqual([
            'DE',
            'IT',
            'GB',
            'US',
        ]);
    });
});
