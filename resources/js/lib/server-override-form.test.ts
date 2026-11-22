import { describe, expect, it } from 'vitest';
import {
    clampMaxPlayers,
    countErrorsByTab,
    defaultOverrideForm,
    firstTabWithErrors,
    formFromConfig,
    isPitstopEligible,
    isPracticeUnavailable,
    madridNow,
    maxPlayersFor,
    overrideTabForField,
    toPayload,
    validateOverrideForm,
} from '@/lib/server-override-form';
import type { OverrideTrack } from '@/lib/server-override-form';

const track: OverrideTrack = {
    id: 1,
    maxPitSlot: 24,
    practiceTrack: { id: 2, maxPitSlot: 16 },
};

const now = new Date('2026-11-14T10:00:00Z');

describe('toPayload', () => {
    it('converts minutes to seconds and clock time to hour and minute', () => {
        const form = defaultOverrideForm();
        form.mode = 'race_weekend';
        form.practice.length_min = '90';
        form.qualify.length_min = '10.5';
        form.race.start_time = '09:05';

        const payload = toPayload(form);
        const sessions = payload.sessions as Record<string, any>;

        expect(sessions.practice.length_sec).toBe(5400);
        expect(sessions.qualify.length_sec).toBe(630);
        expect(sessions.race).toMatchObject({
            length_sec: 1500,
            hour: 9,
            minute: 5,
            laps: null,
        });
    });

    it('omits qualify, warmup and race in practice mode', () => {
        const form = defaultOverrideForm();
        form.mode = 'practice';

        const sessions = toPayload(form).sessions as Record<string, unknown>;

        expect(Object.keys(sessions)).toEqual(['practice']);
    });

    it('sends laps only for lap races', () => {
        const form = defaultOverrideForm();
        form.race.duration_type = 'laps';
        form.race.laps = '12';

        const sessions = toPayload(form).sessions as Record<string, any>;

        expect(sessions.race.laps).toBe(12);
        expect(sessions.race.duration_type).toBe('laps');
    });

    it('turns off the mandatory pitstop when it is not eligible', () => {
        const form = defaultOverrideForm();
        form.race.mandatory_pitstop_enabled = true;
        form.race.length_min = '15';

        const sessions = toPayload(form).sessions as Record<string, any>;

        expect(sessions.race.mandatory_pitstop_enabled).toBe(false);

        form.race.length_min = '30';

        const eligible = toPayload(form).sessions as Record<string, any>;

        expect(eligible.race.mandatory_pitstop_enabled).toBe(true);
    });

    it('does not touch empty passwords and nulls cleared ones', () => {
        const form = defaultOverrideForm();
        form.driver_password = 'secret';
        form.admin_password = 'other';
        form.clear_admin_password = true;

        const payload = toPayload(form);

        expect(payload.driver_password).toBe('secret');
        expect(payload.admin_password).toBeNull();
        expect(payload.spectator_password).toBeNull();
        expect(payload.clear_admin_password).toBe(true);
    });

    it('sends until only when the limited duration is on', () => {
        const form = defaultOverrideForm();
        form.until = '2026-11-20T18:00';

        expect(toPayload(form).until).toBeNull();

        form.until_enabled = true;

        expect(toPayload(form).until).toBe('2026-11-20T18:00');
    });
});

describe('isPitstopEligible', () => {
    it('needs race weekend, a timed race and more than 20 minutes', () => {
        expect(isPitstopEligible('race_weekend', 'time', '21')).toBe(true);
        expect(isPitstopEligible('race_weekend', 'time', '20')).toBe(false);
        expect(isPitstopEligible('race_weekend', 'laps', '60')).toBe(false);
        expect(isPitstopEligible('practice', 'time', '60')).toBe(false);
    });
});

describe('maxPlayersFor', () => {
    it('uses the race track slots in race weekend', () => {
        expect(maxPlayersFor('race_weekend', track)).toBe(24);
    });

    it('uses the practice track slots in practice mode', () => {
        expect(maxPlayersFor('practice', track)).toBe(16);
    });

    it('is zero when the practice track is missing', () => {
        const orphan = { ...track, practiceTrack: null };

        expect(maxPlayersFor('practice', orphan)).toBe(0);
        expect(isPracticeUnavailable('practice', orphan)).toBe(true);
        expect(isPracticeUnavailable('race_weekend', orphan)).toBe(false);
    });

    it('caps at 100 and falls back to 100 without a track', () => {
        expect(maxPlayersFor('race_weekend', undefined)).toBe(100);
        expect(
            maxPlayersFor('race_weekend', { ...track, maxPitSlot: 150 }),
        ).toBe(100);
    });
});

describe('clampMaxPlayers', () => {
    it('lowers values above the maximum only', () => {
        expect(clampMaxPlayers('30', 24)).toBe('24');
        expect(clampMaxPlayers('20', 24)).toBe('20');
        expect(clampMaxPlayers('', 24)).toBe('');
    });
});

describe('formFromConfig', () => {
    it('falls back to defaults when the panel gave nothing', () => {
        expect(formFromConfig(null)).toEqual(defaultOverrideForm());
    });

    it('converts seconds to minutes and hour/minute to clock time', () => {
        const form = formFromConfig({
            server_name: 'ACE',
            max_players: 18,
            cycle_enabled: false,
            server_type: null,
            tuning_type: null,
            driver_password: null,
            admin_password: 'adm',
            spectator_password: null,
            mode: 'race_weekend',
            track_id: 3,
            weather: null,
            weather_behaviour: null,
            initial_grip: null,
            car_ids: [5],
            sessions: {
                practice: { length_sec: 7200, hour: 8, minute: 30 },
            },
        });

        expect(form.practice.length_min).toBe('120');
        expect(form.practice.start_time).toBe('08:30');
        expect(form.qualify.length_min).toBe('10');
        expect(form.server_type).toBe('ranked');
        expect(form.admin_password).toBe('adm');
    });
});

describe('validateOverrideForm', () => {
    const valid = () => {
        const form = defaultOverrideForm();
        form.server_name = 'ACE';
        form.track_id = 1;
        form.car_ids = [1];

        return form;
    };

    it('accepts a complete form', () => {
        expect(validateOverrideForm(valid(), track, now)).toEqual({});
    });

    it('flags missing track, cars and players above the circuit limit', () => {
        const form = valid();
        form.car_ids = [];
        form.max_players = '40';

        const errors = validateOverrideForm(form, track, now);

        expect(errors.car_ids).toBeDefined();
        expect(errors.max_players.params).toEqual({ max: 24 });
        expect(
            validateOverrideForm(form, undefined, now).track_id,
        ).toBeDefined();
    });

    it('blocks practice mode without a practice track', () => {
        const form = valid();
        form.mode = 'practice';

        const errors = validateOverrideForm(
            form,
            { ...track, practiceTrack: null },
            now,
        );

        expect(errors.track_id.key).toBe('admin.override.errTrackPractice');
    });

    it('requires laps or race minutes according to the race type', () => {
        const form = valid();
        form.race.duration_type = 'laps';
        form.race.laps = '';

        expect(
            validateOverrideForm(form, track, now)['sessions.race.laps'],
        ).toBeDefined();

        form.race.duration_type = 'time';
        form.race.length_min = '0';

        expect(
            validateOverrideForm(form, track, now)['sessions.race.length_sec'],
        ).toBeDefined();
    });

    it('requires a future Madrid date when the override is limited', () => {
        const form = valid();
        form.until_enabled = true;
        form.until = '2026-11-14T11:00';

        expect(validateOverrideForm(form, track, now).until).toBeDefined();

        form.until = '2026-11-14T12:00';

        expect(validateOverrideForm(form, track, now).until).toBeUndefined();
    });
});

describe('madridNow', () => {
    it('formats in Madrid time', () => {
        expect(madridNow(new Date('2026-07-01T10:00:00Z'))).toBe(
            '2026-07-01T12:00',
        );
    });
});

describe('overrideTabForField', () => {
    it('maps every field to the tab that holds it', () => {
        expect(overrideTabForField('server_name')).toBe('server');
        expect(overrideTabForField('admin_password')).toBe('server');
        expect(overrideTabForField('track_id')).toBe('event');
        expect(overrideTabForField('initial_grip')).toBe('event');
        expect(overrideTabForField('car_ids')).toBe('cars');
        expect(overrideTabForField('car_ids.3')).toBe('cars');
        expect(overrideTabForField('sessions.race.laps')).toBe('sessions');
        expect(overrideTabForField('until')).toBe('duration');
        expect(overrideTabForField('unknown_field')).toBe('duration');
    });
});

describe('countErrorsByTab', () => {
    it('counts the errors of each tab', () => {
        expect(
            countErrorsByTab([
                'server_name',
                'max_players',
                'sessions.race.laps',
                'until',
            ]),
        ).toEqual({
            server: 2,
            event: 0,
            cars: 0,
            sessions: 1,
            duration: 1,
        });
    });
});

describe('firstTabWithErrors', () => {
    it('returns the first tab in display order, or null without errors', () => {
        expect(firstTabWithErrors(['until', 'car_ids', 'track_id'])).toBe(
            'event',
        );
        expect(firstTabWithErrors([])).toBeNull();
    });
});
