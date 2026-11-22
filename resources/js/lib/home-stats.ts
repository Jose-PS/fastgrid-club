export type StatsPagination = {
    currentPage: number;
    lastPage: number;
    total: number;
    perPage: number;
    from: number | null;
    to: number | null;
    pageName: string;
};

export type NamedOption = { id: number; name: string };

export type BestLapRow = {
    position: number;
    driver: string;
    hidden: boolean;
    isYou: boolean;
    track: string;
    car: string;
    lapMs: number;
    gapMs: number | null;
    achievedAt: string;
};

export type BestLaps = {
    options: { tracks: NamedOption[]; cars: NamedOption[] };
    selected: { trackId: number | null; carId: number | null };
    rows: BestLapRow[];
    pagination: StatsPagination;
};

export type RaceHeat = {
    id: number;
    index: number;
    number: number;
    startsAt: string;
};

export type RaceResultRow = {
    position: number;
    driver: string;
    hidden: boolean;
    isYou: boolean;
    car: string | null;
    lapsCompleted: number;
    totalMs: number | null;
    gapMs: number | null;
    lapped: boolean;
    lapsBehind: number;
    bestLapMs: number | null;
    isFastest: boolean;
    positionsGained: number | null;
    classified: boolean;
};

export type LastRaceDay = {
    heats: RaceHeat[];
    selectedHeatId: number | null;
    hasGrid: boolean;
    fastestLapMs: number | null;
    rows: RaceResultRow[];
    pagination: StatsPagination;
};

export const ALL_OPTION = 'all';

export function homeStatsUrl(
    currentUrl: string,
    changes: Record<string, number | null>,
): string {
    const url = new URL(currentUrl, 'http://localhost');

    Object.entries(changes).forEach(([key, value]) => {
        if (value === null) {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, String(value));
        }
    });

    const query = url.searchParams.toString();

    return `${url.pathname}${query === '' ? '' : `?${query}`}`;
}
