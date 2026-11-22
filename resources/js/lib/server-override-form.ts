import { MADRID_TIME_ZONE } from '@/lib/datetime';

export type OverrideMode = 'practice' | 'race_weekend';
export type RaceDurationType = 'time' | 'laps';
export type SessionKey = 'practice' | 'qualify' | 'warmup' | 'race';

export type OverrideTrack = {
    id: number;
    maxPitSlot: number;
    practiceTrack: { id: number; maxPitSlot: number } | null;
};

export type ConfigSession = {
    length_sec: number;
    hour: number;
    minute: number;
    time_multiplier: number;
    max_wait_to_box: number;
    overtime_waiting_next_session: number;
};

export type ConfigRace = ConfigSession & {
    duration_type: RaceDurationType;
    laps: number | null;
    min_waiting_for_players: number;
    max_waiting_for_players: number;
    mandatory_pitstop_enabled: boolean;
    mandatory_pitstop_window_seconds: number;
    mandatory_pitstop_refuel: boolean;
    mandatory_pitstop_tyre_change: boolean;
};

export type OverrideConfig = {
    server_name: string;
    max_players: number;
    cycle_enabled: boolean;
    server_type: 'ranked' | 'unranked' | null;
    tuning_type: 'allowed' | 'denied' | null;
    driver_password: string | null;
    admin_password: string | null;
    spectator_password: string | null;
    mode: OverrideMode | null;
    track_id: number | null;
    weather: string | null;
    weather_behaviour: 'static' | 'dynamic' | null;
    initial_grip: 'green' | 'fast' | 'optimum' | null;
    car_ids: number[];
    sessions: Partial<
        Record<'practice' | 'qualify' | 'warmup', Partial<ConfigSession>> & {
            race: Partial<ConfigRace>;
        }
    >;
};

export type SessionForm = {
    length_min: string;
    start_time: string;
    time_multiplier: string;
    max_wait_to_box: string;
    overtime_waiting_next_session: string;
};

export type RaceForm = SessionForm & {
    duration_type: RaceDurationType;
    laps: string;
    min_waiting_for_players: string;
    max_waiting_for_players: string;
    mandatory_pitstop_enabled: boolean;
    mandatory_pitstop_window_seconds: string;
    mandatory_pitstop_refuel: boolean;
    mandatory_pitstop_tyre_change: boolean;
};

export type OverrideForm = {
    server_name: string;
    max_players: string;
    cycle_enabled: boolean;
    server_type: 'ranked' | 'unranked';
    tuning_type: 'allowed' | 'denied';
    driver_password: string;
    admin_password: string;
    spectator_password: string;
    clear_driver_password: boolean;
    clear_admin_password: boolean;
    clear_spectator_password: boolean;
    mode: OverrideMode;
    track_id: number | '';
    weather: string;
    weather_behaviour: 'static' | 'dynamic';
    initial_grip: 'green' | 'fast' | 'optimum';
    car_ids: number[];
    until_enabled: boolean;
    until: string;
    practice: SessionForm;
    qualify: SessionForm;
    warmup: SessionForm;
    race: RaceForm;
};

export type OverrideError = {
    key: string;
    params?: Record<string, string | number>;
};

export const MAX_PLAYERS_LIMIT = 100;
export const PITSTOP_MIN_RACE_MINUTES = 20;
export const MAX_PIT_WINDOW_SECONDS = 86400;

const DEFAULT_MAX_PLAYERS = 20;

const session = (
    minutes: number,
    maxWaitToBox: number,
    hour = 14,
    minute = 0,
): SessionForm => ({
    length_min: String(minutes),
    start_time: toTime(hour, minute),
    time_multiplier: '1',
    max_wait_to_box: String(maxWaitToBox),
    overtime_waiting_next_session: '10',
});

export function toTime(hour: number, minute: number): string {
    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

export function splitTime(value: string): { hour: number; minute: number } {
    const [hour, minute] = value.split(':').map(Number);

    return { hour: hour || 0, minute: minute || 0 };
}

export function defaultOverrideForm(): OverrideForm {
    return {
        server_name: '',
        max_players: String(DEFAULT_MAX_PLAYERS),
        cycle_enabled: true,
        server_type: 'ranked',
        tuning_type: 'allowed',
        driver_password: '',
        admin_password: '',
        spectator_password: '',
        clear_driver_password: false,
        clear_admin_password: false,
        clear_spectator_password: false,
        mode: 'race_weekend',
        track_id: '',
        weather: 'clear',
        weather_behaviour: 'static',
        initial_grip: 'optimum',
        car_ids: [],
        until_enabled: false,
        until: '',
        practice: session(120, 10),
        qualify: session(10, 10),
        warmup: session(5, 10),
        race: {
            ...session(25, 60),
            duration_type: 'time',
            laps: '10',
            min_waiting_for_players: '10',
            max_waiting_for_players: '60',
            mandatory_pitstop_enabled: false,
            mandatory_pitstop_window_seconds: '600',
            mandatory_pitstop_refuel: true,
            mandatory_pitstop_tyre_change: true,
        },
    };
}

const text = (value: number | null | undefined, fallback: string): string =>
    value === null || value === undefined ? fallback : String(value);

const minutesText = (seconds: number | undefined, fallback: string): string =>
    seconds === undefined
        ? fallback
        : String(Math.round((seconds / 60) * 100) / 100);

function sessionFromConfig(
    source: Partial<ConfigSession> | undefined,
    base: SessionForm,
): SessionForm {
    if (!source) {
        return base;
    }

    return {
        length_min: minutesText(source.length_sec, base.length_min),
        start_time:
            source.hour === undefined
                ? base.start_time
                : toTime(source.hour, source.minute ?? 0),
        time_multiplier: text(source.time_multiplier, base.time_multiplier),
        max_wait_to_box: text(source.max_wait_to_box, base.max_wait_to_box),
        overtime_waiting_next_session: text(
            source.overtime_waiting_next_session,
            base.overtime_waiting_next_session,
        ),
    };
}

export function formFromConfig(config: OverrideConfig | null): OverrideForm {
    const defaults = defaultOverrideForm();

    if (config === null) {
        return defaults;
    }

    const race = config.sessions.race;

    return {
        ...defaults,
        server_name: config.server_name,
        max_players: String(config.max_players),
        cycle_enabled: config.cycle_enabled,
        server_type: config.server_type ?? defaults.server_type,
        tuning_type: config.tuning_type ?? defaults.tuning_type,
        driver_password: config.driver_password ?? '',
        admin_password: config.admin_password ?? '',
        spectator_password: config.spectator_password ?? '',
        mode: config.mode ?? defaults.mode,
        track_id: config.track_id ?? '',
        weather: config.weather ?? defaults.weather,
        weather_behaviour:
            config.weather_behaviour ?? defaults.weather_behaviour,
        initial_grip: config.initial_grip ?? defaults.initial_grip,
        car_ids: config.car_ids,
        practice: sessionFromConfig(
            config.sessions.practice,
            defaults.practice,
        ),
        qualify: sessionFromConfig(config.sessions.qualify, defaults.qualify),
        warmup: sessionFromConfig(config.sessions.warmup, defaults.warmup),
        race: {
            ...sessionFromConfig(race, defaults.race),
            duration_type: race?.duration_type ?? defaults.race.duration_type,
            laps: text(race?.laps, defaults.race.laps),
            min_waiting_for_players: text(
                race?.min_waiting_for_players,
                defaults.race.min_waiting_for_players,
            ),
            max_waiting_for_players: text(
                race?.max_waiting_for_players,
                defaults.race.max_waiting_for_players,
            ),
            mandatory_pitstop_enabled:
                race?.mandatory_pitstop_enabled ??
                defaults.race.mandatory_pitstop_enabled,
            mandatory_pitstop_window_seconds: text(
                race?.mandatory_pitstop_window_seconds,
                defaults.race.mandatory_pitstop_window_seconds,
            ),
            mandatory_pitstop_refuel:
                race?.mandatory_pitstop_refuel ??
                defaults.race.mandatory_pitstop_refuel,
            mandatory_pitstop_tyre_change:
                race?.mandatory_pitstop_tyre_change ??
                defaults.race.mandatory_pitstop_tyre_change,
        },
    };
}

export function maxPlayersFor(
    mode: OverrideMode,
    track: OverrideTrack | undefined,
): number {
    if (track === undefined) {
        return MAX_PLAYERS_LIMIT;
    }

    const slots =
        mode === 'practice'
            ? (track.practiceTrack?.maxPitSlot ?? 0)
            : track.maxPitSlot;

    return Math.min(slots, MAX_PLAYERS_LIMIT);
}

export function isPracticeUnavailable(
    mode: OverrideMode,
    track: OverrideTrack | undefined,
): boolean {
    return (
        mode === 'practice' &&
        track !== undefined &&
        track.practiceTrack === null
    );
}

export function clampMaxPlayers(current: string, max: number): string {
    const value = Number(current);

    if (current.trim() !== '' && value > max && max > 0) {
        return String(max);
    }

    return current;
}

export function isPitstopEligible(
    mode: OverrideMode,
    durationType: RaceDurationType,
    raceMinutes: string,
): boolean {
    return (
        mode === 'race_weekend' &&
        durationType === 'time' &&
        Number(raceMinutes) > PITSTOP_MIN_RACE_MINUTES
    );
}

const toSeconds = (minutes: string): number => Math.round(Number(minutes) * 60);

const toInt = (value: string): number => Math.round(Number(value));

function sessionPayload(form: SessionForm) {
    const { hour, minute } = splitTime(form.start_time);

    return {
        length_sec: toSeconds(form.length_min),
        hour,
        minute,
        time_multiplier: toInt(form.time_multiplier),
        max_wait_to_box: toInt(form.max_wait_to_box),
        overtime_waiting_next_session: toInt(
            form.overtime_waiting_next_session,
        ),
    };
}

function passwordPayload(value: string, clear: boolean): string | null {
    if (clear || value === '') {
        return null;
    }

    return value;
}

export function toPayload(form: OverrideForm) {
    const raceWeekend = form.mode === 'race_weekend';
    const race = form.race;
    const pitstop =
        raceWeekend &&
        isPitstopEligible(form.mode, race.duration_type, race.length_min) &&
        race.mandatory_pitstop_enabled;

    const sessions: Record<string, unknown> = {
        practice: sessionPayload(form.practice),
    };

    if (raceWeekend) {
        sessions.qualify = sessionPayload(form.qualify);
        sessions.warmup = sessionPayload(form.warmup);
        sessions.race = {
            ...sessionPayload(race),
            duration_type: race.duration_type,
            laps: race.duration_type === 'laps' ? toInt(race.laps) : null,
            min_waiting_for_players: toInt(race.min_waiting_for_players),
            max_waiting_for_players: toInt(race.max_waiting_for_players),
            mandatory_pitstop_enabled: pitstop,
            mandatory_pitstop_window_seconds: toInt(
                race.mandatory_pitstop_window_seconds,
            ),
            mandatory_pitstop_refuel: race.mandatory_pitstop_refuel,
            mandatory_pitstop_tyre_change: race.mandatory_pitstop_tyre_change,
        };
    }

    return {
        server_name: form.server_name.trim(),
        max_players: toInt(form.max_players),
        cycle_enabled: form.cycle_enabled,
        server_type: form.server_type,
        tuning_type: form.tuning_type,
        driver_password: passwordPayload(
            form.driver_password,
            form.clear_driver_password,
        ),
        admin_password: passwordPayload(
            form.admin_password,
            form.clear_admin_password,
        ),
        spectator_password: passwordPayload(
            form.spectator_password,
            form.clear_spectator_password,
        ),
        clear_driver_password: form.clear_driver_password,
        clear_admin_password: form.clear_admin_password,
        clear_spectator_password: form.clear_spectator_password,
        mode: form.mode,
        track_id: form.track_id === '' ? null : form.track_id,
        weather: form.weather,
        weather_behaviour: form.weather_behaviour,
        initial_grip: form.initial_grip,
        car_ids: form.car_ids,
        until: form.until_enabled && form.until !== '' ? form.until : null,
        sessions,
    };
}

export function madridNow(date: Date): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: MADRID_TIME_ZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);
    const get = (type: string): string =>
        parts.find((part) => part.type === type)?.value ?? '00';

    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

const isPositive = (value: string): boolean => Number(value) > 0;

export function validateOverrideForm(
    form: OverrideForm,
    track: OverrideTrack | undefined,
    now: Date,
): Record<string, OverrideError> {
    const errors: Record<string, OverrideError> = {};
    const max = maxPlayersFor(form.mode, track);

    if (form.server_name.trim() === '' || form.server_name.length > 120) {
        errors.server_name = { key: 'admin.override.errServerName' };
    }

    if (track === undefined) {
        errors.track_id = { key: 'admin.override.errTrack' };
    } else if (isPracticeUnavailable(form.mode, track)) {
        errors.track_id = { key: 'admin.override.errTrackPractice' };
    }

    const players = Number(form.max_players);

    if (
        form.max_players.trim() === '' ||
        !Number.isInteger(players) ||
        players < 1 ||
        players > max
    ) {
        errors.max_players = {
            key: 'admin.override.errMaxPlayers',
            params: { max },
        };
    }

    if (form.car_ids.length === 0) {
        errors.car_ids = { key: 'admin.override.errCars' };
    }

    if (form.mode === 'practice' && !isPositive(form.practice.length_min)) {
        errors['sessions.practice.length_sec'] = {
            key: 'admin.override.errPractice',
        };
    }

    if (form.mode === 'race_weekend') {
        if (form.race.duration_type === 'laps') {
            if (!isPositive(form.race.laps)) {
                errors['sessions.race.laps'] = {
                    key: 'admin.override.errRaceLaps',
                };
            }
        } else if (!isPositive(form.race.length_min)) {
            errors['sessions.race.length_sec'] = {
                key: 'admin.override.errRaceLength',
            };
        }

        const window = Number(form.race.mandatory_pitstop_window_seconds);

        if (
            isPitstopEligible(
                form.mode,
                form.race.duration_type,
                form.race.length_min,
            ) &&
            form.race.mandatory_pitstop_enabled &&
            !(window >= 1 && window <= MAX_PIT_WINDOW_SECONDS)
        ) {
            errors['sessions.race.mandatory_pitstop_window_seconds'] = {
                key: 'admin.override.errPitWindow',
                params: { max: MAX_PIT_WINDOW_SECONDS },
            };
        }
    }

    if (form.until_enabled && form.until <= madridNow(now)) {
        errors.until = { key: 'admin.override.errUntil' };
    }

    return errors;
}

export type OverrideTabId =
    | 'server'
    | 'event'
    | 'cars'
    | 'sessions'
    | 'duration';

export const overrideTabIds: OverrideTabId[] = [
    'server',
    'event',
    'cars',
    'sessions',
    'duration',
];

const serverFields = [
    'server_name',
    'max_players',
    'cycle_enabled',
    'server_type',
    'tuning_type',
    'driver_password',
    'admin_password',
    'spectator_password',
    'clear_driver_password',
    'clear_admin_password',
    'clear_spectator_password',
];

const eventFields = [
    'mode',
    'track_id',
    'weather',
    'weather_behaviour',
    'initial_grip',
];

export function overrideTabForField(key: string): OverrideTabId {
    const root = key.split('.')[0];

    if (serverFields.includes(root)) {
        return 'server';
    }

    if (eventFields.includes(root)) {
        return 'event';
    }

    if (root === 'car_ids') {
        return 'cars';
    }

    if (root === 'sessions') {
        return 'sessions';
    }

    return 'duration';
}

export function countErrorsByTab(
    keys: string[],
): Record<OverrideTabId, number> {
    const counts: Record<OverrideTabId, number> = {
        server: 0,
        event: 0,
        cars: 0,
        sessions: 0,
        duration: 0,
    };

    keys.forEach((key) => {
        counts[overrideTabForField(key)] += 1;
    });

    return counts;
}

export function firstTabWithErrors(keys: string[]): OverrideTabId | null {
    const counts = countErrorsByTab(keys);

    return overrideTabIds.find((id) => counts[id] > 0) ?? null;
}
