import { describe, expect, it } from 'vitest';
import {
    countErrorsByWeekday,
    firstWeekdayWithErrors,
    hasRaceWithoutQualifying,
    madridWeekday,
} from '@/lib/schedule-form';

describe('hasRaceWithoutQualifying', () => {
    it('warns when the segment has a race and no qualifying minutes', () => {
        expect(hasRaceWithoutQualifying(true, false, '')).toBe(true);
        expect(hasRaceWithoutQualifying(true, true, '')).toBe(true);
        expect(hasRaceWithoutQualifying(true, true, '0')).toBe(true);
    });

    it('does not warn when the race has qualifying minutes', () => {
        expect(hasRaceWithoutQualifying(true, true, '30')).toBe(false);
    });

    it('does not warn when the segment has no race', () => {
        expect(hasRaceWithoutQualifying(false, false, '')).toBe(false);
        expect(hasRaceWithoutQualifying(false, true, '0')).toBe(false);
    });
});

describe('countErrorsByWeekday', () => {
    it('groups the dotted segment errors under the weekday of their row', () => {
        const weekdays = [1, 1, 3, 7];

        expect(
            countErrorsByWeekday(
                {
                    'segments.0.starts_at': 'a',
                    'segments.1.qualifying_minutes': 'b',
                    'segments.1.race_minutes': 'c',
                    'segments.3.weather': 'd',
                },
                weekdays,
            ),
        ).toEqual({ 1: 3, 7: 1 });
    });

    it('ignores global errors, empty messages and unknown rows', () => {
        expect(
            countErrorsByWeekday(
                {
                    segments: 'global',
                    apply: 'x',
                    'segments.0.starts_at': undefined,
                    'segments.9.starts_at': 'z',
                },
                [2],
            ),
        ).toEqual({});
    });
});

describe('firstWeekdayWithErrors', () => {
    it('returns the first weekday in week order that has errors', () => {
        const order = [1, 2, 3, 4, 5, 6, 7];

        expect(firstWeekdayWithErrors({ 5: 1, 3: 2 }, order)).toBe(3);
        expect(firstWeekdayWithErrors({}, order)).toBeNull();
    });
});

describe('madridWeekday', () => {
    it('uses the Madrid calendar day regardless of the runtime zone', () => {
        expect(madridWeekday(new Date('2026-11-14T12:00:00Z'))).toBe(6);
        expect(madridWeekday(new Date('2026-11-14T23:30:00Z'))).toBe(7);
        expect(madridWeekday(new Date('2026-11-15T23:30:00Z'))).toBe(1);
    });
});
