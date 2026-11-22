import { describe, expect, it } from 'vitest';
import type { Translate } from '@/hooks/use-t';
import { segmentDetail, segmentSessions, segmentTitle } from '@/lib/schedule';
import type { ScheduleSegment } from '@/lib/schedule';

const texts: Record<string, string> = {
    'cal.free': 'Práctica libre',
    'cal.loop': 'en bucle',
    'cal.sessPractice': 'Práctica :minutes min',
    'cal.sessQualy': 'Qualy :minutes min',
    'cal.sessRaceTime': 'Carrera :minutes min',
    'cal.sessRaceLap': 'Carrera :laps vuelta',
    'cal.sessRaceLaps': 'Carrera :laps vueltas',
};

const t: Translate = (key, params) =>
    (texts[key] ?? key).replace(/:([A-Za-z_]+)/g, (match, name: string) =>
        params && name in params ? String(params[name]) : match,
    );

const base: ScheduleSegment = {
    startsAt: '2026-11-07T00:00:00+01:00',
    endsAt: '2026-11-07T18:00:00+01:00',
    isRace: false,
    practiceMinutes: 120,
    qualifyingMinutes: null,
    raceType: null,
    raceMinutes: null,
    raceLaps: null,
    status: 'pending',
};

describe('schedule segments', () => {
    it('describes a practice-only segment', () => {
        expect(segmentTitle(base, t)).toBe('Práctica libre');
        expect(segmentDetail(base, t)).toBe('Práctica 120 min · en bucle');
    });

    it('lists the sessions of a timed race segment', () => {
        const segment: ScheduleSegment = {
            ...base,
            isRace: true,
            practiceMinutes: null,
            qualifyingMinutes: 30,
            raceType: 'time',
            raceMinutes: 20,
        };

        expect(segmentTitle(segment, t)).toBe('Qualy 30 min · Carrera 20 min');
        expect(segmentDetail(segment, t)).toBe('en bucle');
    });

    it('writes lap races in laps and handles a single lap', () => {
        const segment: ScheduleSegment = {
            ...base,
            isRace: true,
            practiceMinutes: 15,
            qualifyingMinutes: 30,
            raceType: 'laps',
            raceLaps: 10,
        };

        expect(segmentTitle(segment, t)).toBe(
            'Práctica 15 min · Qualy 30 min · Carrera 10 vueltas',
        );
        expect(segmentSessions({ ...segment, raceLaps: 1 }, t).at(-1)).toBe(
            'Carrera 1 vuelta',
        );
    });
});
