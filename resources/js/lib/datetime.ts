export type Locale = 'es' | 'en';

export type ZoneAbbreviation = 'CET' | 'CEST';

export const MADRID_TIME_ZONE = 'Europe/Madrid';

const LOCALE_TAGS: Record<Locale, string> = {
    es: 'es-ES',
    en: 'en-GB',
};

export function localeTag(locale: string): string {
    return locale === 'en' ? LOCALE_TAGS.en : LOCALE_TAGS.es;
}

export function zoneAbbreviation(date: Date): ZoneAbbreviation {
    const offset = new Intl.DateTimeFormat('en-GB', {
        timeZone: MADRID_TIME_ZONE,
        timeZoneName: 'longOffset',
    })
        .formatToParts(date)
        .find((part) => part.type === 'timeZoneName')?.value;

    return offset === 'GMT+02:00' ? 'CEST' : 'CET';
}

export function formatDate(
    date: Date,
    locale: string,
    options: Intl.DateTimeFormatOptions = {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    },
): string {
    return new Intl.DateTimeFormat(localeTag(locale), {
        ...options,
        timeZone: MADRID_TIME_ZONE,
    }).format(date);
}

export function formatTime(date: Date, locale: string): string {
    return new Intl.DateTimeFormat(localeTag(locale), {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone: MADRID_TIME_ZONE,
    }).format(date);
}

export function formatDateTime(
    date: Date,
    locale: string,
    options?: Intl.DateTimeFormatOptions,
): string {
    return `${formatDate(date, locale, options)}, ${formatTime(date, locale)} ${zoneAbbreviation(date)}`;
}

export function formatNumber(
    value: number,
    locale: string,
    options?: Intl.NumberFormatOptions,
): string {
    return new Intl.NumberFormat(localeTag(locale), options).format(value);
}
