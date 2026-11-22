import type { Translate } from '@/hooks/use-t';

export type SegmentStatus = 'done' | 'now' | 'pending';

export type ScheduleSegment = {
    startsAt: string;
    endsAt: string;
    isRace: boolean;
    practiceMinutes: number | null;
    qualifyingMinutes: number | null;
    raceType: 'time' | 'laps' | null;
    raceMinutes: number | null;
    raceLaps: number | null;
    status: SegmentStatus;
};

export type ScheduleDay = {
    key: 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';
    date: string;
    isToday: boolean;
    isPast: boolean;
    hasRace: boolean;
    segments: ScheduleSegment[];
};

export function segmentSessions(
    segment: ScheduleSegment,
    t: Translate,
): string[] {
    const sessions: string[] = [];

    if (segment.practiceMinutes !== null) {
        sessions.push(
            t('cal.sessPractice', { minutes: segment.practiceMinutes }),
        );
    }

    if (segment.qualifyingMinutes !== null) {
        sessions.push(
            t('cal.sessQualy', { minutes: segment.qualifyingMinutes }),
        );
    }

    if (segment.raceType === 'laps' && segment.raceLaps !== null) {
        sessions.push(
            t(segment.raceLaps === 1 ? 'cal.sessRaceLap' : 'cal.sessRaceLaps', {
                laps: segment.raceLaps,
            }),
        );
    } else if (segment.raceType === 'time' && segment.raceMinutes !== null) {
        sessions.push(t('cal.sessRaceTime', { minutes: segment.raceMinutes }));
    }

    return sessions;
}

export function segmentTitle(segment: ScheduleSegment, t: Translate): string {
    return segment.isRace
        ? segmentSessions(segment, t).join(' · ')
        : t('cal.free');
}

export function segmentDetail(segment: ScheduleSegment, t: Translate): string {
    const sessions = segment.isRace ? [] : segmentSessions(segment, t);

    return [...sessions, t('cal.loop')].join(' · ');
}
