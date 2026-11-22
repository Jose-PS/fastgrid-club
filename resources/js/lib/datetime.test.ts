import { describe, expect, it } from 'vitest';
import {
    formatDate,
    formatDateTime,
    formatNumber,
    formatTime,
    localeTag,
    zoneAbbreviation,
} from './datetime';

describe('zoneAbbreviation', () => {
    it('is CET in winter', () => {
        expect(zoneAbbreviation(new Date('2026-01-15T12:00:00Z'))).toBe('CET');
    });

    it('is CEST in summer', () => {
        expect(zoneAbbreviation(new Date('2026-07-15T12:00:00Z'))).toBe('CEST');
    });

    it('switches to CEST at the last Sunday of March', () => {
        expect(zoneAbbreviation(new Date('2026-03-29T00:59:59Z'))).toBe('CET');
        expect(zoneAbbreviation(new Date('2026-03-29T01:00:00Z'))).toBe('CEST');
    });

    it('switches back to CET at the last Sunday of October', () => {
        expect(zoneAbbreviation(new Date('2026-10-25T00:59:59Z'))).toBe('CEST');
        expect(zoneAbbreviation(new Date('2026-10-25T01:00:00Z'))).toBe('CET');
    });
});

describe('formatting', () => {
    it('maps locales to regional tags', () => {
        expect(localeTag('es')).toBe('es-ES');
        expect(localeTag('en')).toBe('en-GB');
        expect(localeTag('fr')).toBe('es-ES');
    });

    it('formats time in Madrid regardless of the runtime zone', () => {
        expect(formatTime(new Date('2026-11-07T17:00:00Z'), 'es')).toBe(
            '18:00',
        );
        expect(formatTime(new Date('2026-07-04T16:00:00Z'), 'en')).toBe(
            '18:00',
        );
    });

    it('formats the date in the requested language', () => {
        const date = new Date('2026-11-07T17:00:00Z');

        expect(formatDate(date, 'es')).toBe('sábado, 7 de noviembre');
        expect(formatDate(date, 'en')).toBe('Saturday 7 November');
    });

    it('appends the calculated zone to date and time', () => {
        expect(formatDateTime(new Date('2026-11-07T17:00:00Z'), 'es')).toBe(
            'sábado, 7 de noviembre, 18:00 CET',
        );
        expect(formatDateTime(new Date('2026-07-04T16:00:00Z'), 'en')).toBe(
            'Saturday 4 July, 18:00 CEST',
        );
    });

    it('formats numbers with the locale separators', () => {
        expect(formatNumber(1234.5, 'es', { minimumFractionDigits: 1 })).toBe(
            '1234,5',
        );
        expect(formatNumber(1234.5, 'en', { minimumFractionDigits: 1 })).toBe(
            '1,234.5',
        );
    });
});
