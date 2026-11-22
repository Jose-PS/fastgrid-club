import { formatDate } from '@/lib/datetime';

const SHORT_DATE: Intl.DateTimeFormatOptions = {
    day: 'numeric',
    month: 'short',
};

const DAY_MS = 86400000;

export function weekRange(
    week: { startsAt: string; endsAt: string },
    locale: string,
): string {
    const first = new Date(week.startsAt);
    const last = new Date(new Date(week.endsAt).getTime() - DAY_MS);

    return `${formatDate(first, locale, SHORT_DATE)} – ${formatDate(last, locale, SHORT_DATE)}`;
}
