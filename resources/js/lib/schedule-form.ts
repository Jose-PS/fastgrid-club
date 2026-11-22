export function hasRaceWithoutQualifying(
    race: boolean,
    qualifying: boolean,
    qualifyingMinutes: string,
): boolean {
    return race && (!qualifying || !(Number(qualifyingMinutes) > 0));
}

export function countErrorsByWeekday(
    errors: Record<string, string | undefined>,
    weekdays: number[],
): Record<number, number> {
    const counts: Record<number, number> = {};

    for (const [key, message] of Object.entries(errors)) {
        const match = /^segments\.(\d+)\./.exec(key);

        if (!message || !match) {
            continue;
        }

        const weekday = weekdays[Number(match[1])];

        if (weekday !== undefined) {
            counts[weekday] = (counts[weekday] ?? 0) + 1;
        }
    }

    return counts;
}

export function firstWeekdayWithErrors(
    counts: Record<number, number>,
    order: number[],
): number | null {
    return order.find((weekday) => (counts[weekday] ?? 0) > 0) ?? null;
}

const MADRID_WEEKDAYS: Record<string, number> = {
    Mon: 1,
    Tue: 2,
    Wed: 3,
    Thu: 4,
    Fri: 5,
    Sat: 6,
    Sun: 7,
};

export function madridWeekday(date: Date): number {
    const name = new Intl.DateTimeFormat('en-US', {
        weekday: 'short',
        timeZone: 'Europe/Madrid',
    }).format(date);

    return MADRID_WEEKDAYS[name] ?? 1;
}
