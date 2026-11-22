import {
    sectionClass,
    sectionTitleClass,
    warningClass,
} from '@/components/account-styles';
import { useT } from '@/hooks/use-t';
import { hasRaceWithoutQualifying } from '@/lib/schedule-form';
import type { RaceForm } from '@/lib/server-override-form';
import { cn } from '@/lib/utils';
import type { OverrideTabContext } from './context';
import { Check, Choice, NumberField, SessionFields, hintClass } from './fields';

export function SessionsTab({ ctx }: { ctx: OverrideTabContext }) {
    const t = useT();
    const {
        data,
        errors,
        options,
        label,
        setSession,
        setRace,
        raceWeekend,
        pitstopEligible,
    } = ctx;
    const enums = options.enums;

    return (
        <div
            className={cn(
                'grid gap-5',
                raceWeekend && 'lg:grid-cols-2 lg:items-start',
            )}
        >
            <section
                aria-labelledby="override-practice-title"
                className={sectionClass}
            >
                <h2 id="override-practice-title" className={sectionTitleClass}>
                    {t('admin.override.sessionPractice')}
                </h2>
                <SessionFields
                    id="override-practice"
                    value={data.practice}
                    errors={errors}
                    compact={raceWeekend}
                    lengthKey="sessions.practice.length_sec"
                    onChange={(patch) => setSession('practice', patch)}
                />
            </section>
            {raceWeekend ? (
                <>
                    <section
                        aria-labelledby="override-qualify-title"
                        className={sectionClass}
                    >
                        <h2
                            id="override-qualify-title"
                            className={sectionTitleClass}
                        >
                            {t('admin.override.sessionQualify')}
                        </h2>
                        <SessionFields
                            id="override-qualify"
                            value={data.qualify}
                            errors={errors}
                            compact
                            lengthKey="sessions.qualify.length_sec"
                            onChange={(patch) => setSession('qualify', patch)}
                        />
                        {hasRaceWithoutQualifying(
                            true,
                            true,
                            data.qualify.length_min,
                        ) ? (
                            <p role="status" className={warningClass}>
                                {t('admin.schedule.noQualyWarning')}
                            </p>
                        ) : null}
                    </section>
                    <section
                        aria-labelledby="override-warmup-title"
                        className={sectionClass}
                    >
                        <h2
                            id="override-warmup-title"
                            className={sectionTitleClass}
                        >
                            {t('admin.override.sessionWarmup')}
                        </h2>
                        <SessionFields
                            id="override-warmup"
                            value={data.warmup}
                            errors={errors}
                            compact
                            lengthKey="sessions.warmup.length_sec"
                            onChange={(patch) => setSession('warmup', patch)}
                        />
                    </section>
                    <section
                        aria-labelledby="override-race-title"
                        className={cn(sectionClass, 'lg:col-span-2')}
                    >
                        <h2
                            id="override-race-title"
                            className={sectionTitleClass}
                        >
                            {t('admin.override.sessionRace')}
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <Choice
                                id="override-race-type"
                                label={t('admin.override.raceType')}
                                value={data.race.duration_type}
                                values={enums.race_duration_type}
                                labelFor={(value) =>
                                    label('admin.override.raceType_', value)
                                }
                                error={errors['sessions.race.duration_type']}
                                onChange={(value) =>
                                    setRace({
                                        duration_type:
                                            value as RaceForm['duration_type'],
                                    })
                                }
                            />
                            {data.race.duration_type === 'laps' ? (
                                <NumberField
                                    id="override-race-laps"
                                    label={t('admin.override.laps')}
                                    min={1}
                                    max={1000}
                                    step={1}
                                    value={data.race.laps}
                                    error={errors['sessions.race.laps']}
                                    onChange={(laps) => setRace({ laps })}
                                />
                            ) : null}
                            <NumberField
                                id="override-race-min-wait"
                                label={t('admin.override.minWaiting')}
                                min={0}
                                max={3600}
                                step={1}
                                value={data.race.min_waiting_for_players}
                                onChange={(min_waiting_for_players) =>
                                    setRace({
                                        min_waiting_for_players,
                                    })
                                }
                            />
                            <NumberField
                                id="override-race-max-wait"
                                label={t('admin.override.maxWaiting')}
                                min={0}
                                max={3600}
                                step={1}
                                value={data.race.max_waiting_for_players}
                                onChange={(max_waiting_for_players) =>
                                    setRace({
                                        max_waiting_for_players,
                                    })
                                }
                            />
                        </div>
                        <SessionFields
                            id="override-race"
                            value={data.race}
                            errors={errors}
                            lengthKey="sessions.race.length_sec"
                            onChange={(patch) => setRace(patch)}
                        />
                        <div className="grid gap-3 rounded-md border border-border p-4">
                            <Check
                                id="override-pitstop"
                                label={t('admin.override.pitstop')}
                                checked={
                                    pitstopEligible &&
                                    data.race.mandatory_pitstop_enabled
                                }
                                disabled={!pitstopEligible}
                                onChange={(mandatory_pitstop_enabled) =>
                                    setRace({
                                        mandatory_pitstop_enabled,
                                    })
                                }
                            />
                            {pitstopEligible ? null : (
                                <p className={hintClass}>
                                    {t('admin.override.pitstopUnavailable')}
                                </p>
                            )}
                            <div className="grid gap-4 sm:grid-cols-3">
                                <NumberField
                                    id="override-pitstop-window"
                                    label={t('admin.override.pitstopWindow')}
                                    min={1}
                                    max={86400}
                                    step={1}
                                    disabled={
                                        !pitstopEligible ||
                                        !data.race.mandatory_pitstop_enabled
                                    }
                                    value={
                                        data.race
                                            .mandatory_pitstop_window_seconds
                                    }
                                    error={
                                        errors[
                                            'sessions.race.mandatory_pitstop_window_seconds'
                                        ]
                                    }
                                    onChange={(
                                        mandatory_pitstop_window_seconds,
                                    ) =>
                                        setRace({
                                            mandatory_pitstop_window_seconds,
                                        })
                                    }
                                />
                                <Check
                                    id="override-pitstop-refuel"
                                    label={t('admin.override.refuel')}
                                    checked={data.race.mandatory_pitstop_refuel}
                                    disabled={
                                        !pitstopEligible ||
                                        !data.race.mandatory_pitstop_enabled
                                    }
                                    onChange={(mandatory_pitstop_refuel) =>
                                        setRace({
                                            mandatory_pitstop_refuel,
                                        })
                                    }
                                />
                                <Check
                                    id="override-pitstop-tyres"
                                    label={t('admin.override.tyreChange')}
                                    checked={
                                        data.race.mandatory_pitstop_tyre_change
                                    }
                                    disabled={
                                        !pitstopEligible ||
                                        !data.race.mandatory_pitstop_enabled
                                    }
                                    onChange={(mandatory_pitstop_tyre_change) =>
                                        setRace({
                                            mandatory_pitstop_tyre_change,
                                        })
                                    }
                                />
                            </div>
                        </div>
                    </section>
                </>
            ) : null}
        </div>
    );
}
