import type {
    OverrideForm,
    OverrideTrack,
    RaceForm,
    SessionForm,
    SessionKey,
} from '@/lib/server-override-form';
import type { OverrideMode } from '@/lib/server-override-form';
import type { ProposalCar } from '@/types/proposals';

export type OverrideSummary = {
    mode: OverrideMode | null;
    track: string | null;
    server_name: string | null;
    max_players: number | null;
    cars: string[];
    weather: string | null;
    weather_behaviour: string | null;
    initial_grip: string | null;
    server_type: string | null;
    tuning_type: string | null;
    cycle_enabled: boolean | null;
    practice_seconds: number | null;
    qualifying_seconds: number | null;
    warmup_seconds: number | null;
    race_duration_type: 'time' | 'laps' | null;
    race_seconds: number | null;
    race_laps: number | null;
    mandatory_pitstop: boolean;
};

export type ActiveOverride = {
    id: number;
    created_by: string | null;
    created_at: string;
    until: string | null;
    summary: OverrideSummary;
};

export type PageOptions = {
    tracks: (OverrideTrack & {
        display: string;
        lengthM: number | null;
        country: string | null;
    })[];
    cars: ProposalCar[];
    enums: Record<string, string[]>;
};

export type OverrideTabContext = {
    data: OverrideForm;
    errors: Record<string, string | undefined>;
    options: PageOptions;
    maxPlayers: number;
    raceWeekend: boolean;
    practiceMissing: boolean;
    pitstopEligible: boolean;
    label: (prefix: string, value: string | null) => string;
    set: (patch: Partial<OverrideForm>) => void;
    setSession: (
        key: Exclude<SessionKey, 'race'>,
        patch: Partial<SessionForm>,
    ) => void;
    setRace: (patch: Partial<RaceForm>) => void;
};
