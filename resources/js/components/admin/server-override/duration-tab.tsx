import {
    fieldErrorClass,
    sectionClass,
    sectionTitleClass,
    statusClass,
} from '@/components/account-styles';
import { Input } from '@/components/ui/input';
import { useT } from '@/hooks/use-t';
import { madridNow } from '@/lib/server-override-form';
import { cn } from '@/lib/utils';
import type { ActiveOverride, OverrideTabContext } from './context';
import { Fact, Field, checkboxClass, hintClass } from './fields';

const minutesLabel = (seconds: number | null): string =>
    seconds === null ? '-' : `${Math.round((seconds / 60) * 100) / 100} min`;

export function DurationTab({
    ctx,
    override,
    players,
    issues,
    dateTime,
}: {
    ctx: OverrideTabContext;
    override: ActiveOverride | null;
    players: number | null;
    issues: { key: string; text: string }[];
    dateTime: (value: string) => string;
}) {
    const t = useT();
    const { data, errors, options, label, set, raceWeekend, practiceMissing } =
        ctx;

    return (
        <div className="grid gap-5">
            <section
                aria-labelledby="override-duration"
                className={sectionClass}
            >
                <h2 id="override-duration" className={sectionTitleClass}>
                    {t('admin.override.sectionDuration')}
                </h2>
                <div
                    role="radiogroup"
                    aria-labelledby="override-duration"
                    className="flex flex-wrap gap-x-6"
                >
                    <label className="flex min-h-11 items-center gap-2.5 text-sm">
                        <input
                            type="radio"
                            name="override-duration"
                            className={checkboxClass}
                            checked={!data.until_enabled}
                            onChange={() => set({ until_enabled: false })}
                        />
                        {t('admin.override.indefinite')}
                    </label>
                    <label className="flex min-h-11 items-center gap-2.5 text-sm">
                        <input
                            type="radio"
                            name="override-duration"
                            className={checkboxClass}
                            checked={data.until_enabled}
                            onChange={() => set({ until_enabled: true })}
                        />
                        {t('admin.override.untilDate')}
                    </label>
                </div>
                {data.until_enabled ? (
                    <div className="max-w-xs">
                        <Field
                            label={t('admin.override.untilLabel')}
                            htmlFor="override-until"
                            error={errors.until}
                        >
                            <Input
                                id="override-until"
                                type="datetime-local"
                                min={madridNow(new Date())}
                                value={data.until}
                                aria-invalid={errors.until ? true : undefined}
                                onChange={(event) =>
                                    set({ until: event.target.value })
                                }
                            />
                        </Field>
                    </div>
                ) : null}
            </section>
            <section
                aria-labelledby="override-preview"
                className={sectionClass}
            >
                <h2 id="override-preview" className={sectionTitleClass}>
                    {t('admin.override.sectionPreview')}
                </h2>
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Fact
                        label={t('admin.override.mode')}
                        value={label('admin.override.mode_', data.mode)}
                    />
                    <Fact
                        label={t('admin.override.track')}
                        value={
                            options.tracks.find(
                                (item) => item.id === data.track_id,
                            )?.display ?? '-'
                        }
                    />
                    <Fact
                        label={t('admin.override.maxPlayers')}
                        value={data.max_players || '-'}
                    />
                    <Fact
                        label={t('admin.override.cars')}
                        value={data.car_ids.length}
                    />
                    <Fact
                        label={t('admin.override.weather')}
                        value={label('admin.schedule.weather_', data.weather)}
                    />
                    <Fact
                        label={t('admin.override.sessionPractice')}
                        value={`${data.practice.length_min} min`}
                    />
                    {raceWeekend ? (
                        <>
                            <Fact
                                label={t('admin.override.sessionQualify')}
                                value={`${data.qualify.length_min} min`}
                            />
                            <Fact
                                label={t('admin.override.sessionRace')}
                                value={
                                    data.race.duration_type === 'laps'
                                        ? t('admin.override.nLaps', {
                                              n: data.race.laps,
                                          })
                                        : `${data.race.length_min} min`
                                }
                            />
                        </>
                    ) : null}
                    <Fact
                        label={t('admin.override.until')}
                        value={
                            data.until_enabled && data.until
                                ? data.until.replace('T', ' ')
                                : t('admin.override.indefinite')
                        }
                    />
                </dl>
                {issues.length === 0 && !practiceMissing ? (
                    <p role="status" className={statusClass}>
                        {t('admin.override.previewOk')}
                    </p>
                ) : (
                    <ul className="grid gap-1.5">
                        {issues.map((issue) => (
                            <li key={issue.key} className={fieldErrorClass}>
                                {issue.text}
                            </li>
                        ))}
                    </ul>
                )}
                <p className={hintClass}>
                    {players === null
                        ? t('admin.override.restartWarning')
                        : t('admin.override.restartWarningPlayers', {
                              players,
                          })}
                </p>
            </section>
            {override ? (
                <section
                    aria-labelledby="override-active"
                    className={cn(sectionClass, 'border-primary')}
                >
                    <h2 id="override-active" className={sectionTitleClass}>
                        {t('admin.override.activeTitle')}
                    </h2>
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Fact
                            label={t('admin.override.since')}
                            value={dateTime(override.created_at)}
                        />
                        <Fact
                            label={t('admin.override.until')}
                            value={
                                override.until
                                    ? dateTime(override.until)
                                    : t('admin.override.indefinite')
                            }
                        />
                        <Fact
                            label={t('admin.override.createdBy')}
                            value={override.created_by ?? '-'}
                        />
                        <Fact
                            label={t('admin.override.mode')}
                            value={label(
                                'admin.override.mode_',
                                override.summary.mode,
                            )}
                        />
                        <Fact
                            label={t('admin.override.track')}
                            value={override.summary.track ?? '-'}
                        />
                        <Fact
                            label={t('admin.override.serverName')}
                            value={override.summary.server_name ?? '-'}
                        />
                        <Fact
                            label={t('admin.override.maxPlayers')}
                            value={override.summary.max_players ?? '-'}
                        />
                        <Fact
                            label={t('admin.override.cars')}
                            value={override.summary.cars.join(', ') || '-'}
                        />
                        <Fact
                            label={t('admin.override.sessionPractice')}
                            value={minutesLabel(
                                override.summary.practice_seconds,
                            )}
                        />
                        {override.summary.mode === 'race_weekend' ? (
                            <>
                                <Fact
                                    label={t('admin.override.sessionQualify')}
                                    value={minutesLabel(
                                        override.summary.qualifying_seconds,
                                    )}
                                />
                                <Fact
                                    label={t('admin.override.sessionRace')}
                                    value={
                                        override.summary.race_duration_type ===
                                        'laps'
                                            ? t('admin.override.nLaps', {
                                                  n:
                                                      override.summary
                                                          .race_laps ?? 0,
                                              })
                                            : minutesLabel(
                                                  override.summary.race_seconds,
                                              )
                                    }
                                />
                            </>
                        ) : null}
                        <Fact
                            label={t('admin.override.weather')}
                            value={label(
                                'admin.schedule.weather_',
                                override.summary.weather,
                            )}
                        />
                    </dl>
                </section>
            ) : null}
        </div>
    );
}
