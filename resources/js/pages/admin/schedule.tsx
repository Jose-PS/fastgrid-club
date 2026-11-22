import { Head, useForm, usePage } from '@inertiajs/react';
import { Copy, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import {
    fieldErrorClass,
    sectionClass,
    sectionTitleClass,
} from '@/components/account-styles';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs } from '@/components/ui/tabs';
import type { TabItem } from '@/components/ui/tabs';
import { useT } from '@/hooks/use-t';
import {
    countErrorsByWeekday,
    firstWeekdayWithErrors,
    hasRaceWithoutQualifying,
    madridWeekday,
} from '@/lib/schedule-form';
import { buildTabUrl, readTabFromUrl, resolveTabId } from '@/lib/tab-query';
import { cn } from '@/lib/utils';
import { store } from '@/routes/admin/schedule';

type RaceType = 'time' | 'laps';

type Weather =
    | 'clear'
    | 'scattered_clouds'
    | 'broken_clouds'
    | 'overcast'
    | 'drizzle'
    | 'rain'
    | 'heavy_rain'
    | 'damp';

type ScheduleSegment = {
    weekday: number;
    starts_at: string;
    practice_minutes: number | null;
    qualifying_minutes: number | null;
    race_duration_type: RaceType | null;
    race_minutes: number | null;
    race_laps: number | null;
    start_hour: number;
    weather: Weather;
};

type Schedule = {
    pending: boolean;
    effective_from: string;
    segments: ScheduleSegment[];
};

type SegmentRow = {
    key: number;
    weekday: number;
    starts_at: string;
    practice: boolean;
    practice_minutes: string;
    qualifying: boolean;
    qualifying_minutes: string;
    race: boolean;
    race_duration_type: RaceType;
    race_value: string;
    start_hour: number;
    weather: Weather;
};

type ScheduleForm = {
    apply: 'tomorrow' | 'now';
    segments: SegmentRow[];
};

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

const TAB_IDS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

const TAB_QUERY_KEY = 'tab';

const tabIdFor = (weekday: number): string => TAB_IDS[weekday - 1];

const replaceTabUrl = (id: string): void => {
    window.history.replaceState(
        window.history.state,
        '',
        buildTabUrl(
            window.location.pathname +
                window.location.search +
                window.location.hash,
            TAB_QUERY_KEY,
            id,
        ),
    );
};

const snapshotOf = (rows: SegmentRow[]): string =>
    JSON.stringify(rows.map(toPayload));

const HOURS = Array.from({ length: 24 }, (_, hour) => hour);

const WEATHERS: Weather[] = [
    'clear',
    'scattered_clouds',
    'broken_clouds',
    'overcast',
    'drizzle',
    'rain',
    'heavy_rain',
    'damp',
];

const formatHour = (hour: number): string =>
    `${hour.toString().padStart(2, '0')}:00`;

let nextKey = 1;

const toRow = (segment: ScheduleSegment): SegmentRow => {
    const type = segment.race_duration_type;

    return {
        key: nextKey++,
        weekday: segment.weekday,
        starts_at: segment.starts_at,
        practice: segment.practice_minutes !== null,
        practice_minutes: segment.practice_minutes?.toString() ?? '',
        qualifying: segment.qualifying_minutes !== null,
        qualifying_minutes: segment.qualifying_minutes?.toString() ?? '',
        race: type !== null,
        race_duration_type: type ?? 'time',
        race_value:
            (type === 'laps'
                ? segment.race_laps
                : segment.race_minutes
            )?.toString() ?? '',
        start_hour: segment.start_hour,
        weather: segment.weather,
    };
};

const sortRows = (rows: SegmentRow[]): SegmentRow[] =>
    [...rows].sort(
        (a, b) =>
            a.weekday - b.weekday || a.starts_at.localeCompare(b.starts_at),
    );

const numberOrNull = (value: string): number | null =>
    value.trim() === '' ? null : Number(value);

const toPayload = (row: SegmentRow) => ({
    weekday: row.weekday,
    starts_at: row.starts_at,
    practice_minutes: row.practice ? numberOrNull(row.practice_minutes) : null,
    qualifying_minutes: row.qualifying
        ? numberOrNull(row.qualifying_minutes)
        : null,
    race_duration_type: row.race ? row.race_duration_type : null,
    race_minutes:
        row.race && row.race_duration_type === 'time'
            ? numberOrNull(row.race_value)
            : null,
    race_laps:
        row.race && row.race_duration_type === 'laps'
            ? numberOrNull(row.race_value)
            : null,
    start_hour: row.start_hour,
    weather: row.weather,
});

const checkboxClass = 'size-5 accent-primary';

export default function AdminSchedule({
    schedule,
}: {
    schedule: Schedule | null;
}) {
    const t = useT();
    const form = useForm<ScheduleForm>({
        apply: 'tomorrow',
        segments: sortRows((schedule?.segments ?? []).map(toRow)),
    });
    const [confirming, setConfirming] = useState(false);
    const pageUrl = usePage().url;
    const [activeTab, setActiveTab] = useState(() =>
        resolveTabId(
            TAB_IDS,
            readTabFromUrl(pageUrl, TAB_QUERY_KEY),
            tabIdFor(madridWeekday(new Date())),
        ),
    );
    const errors: Record<string, string | undefined> = form.errors;
    const rows = form.data.segments;
    const [savedSnapshot, setSavedSnapshot] = useState(() =>
        snapshotOf(form.data.segments),
    );
    const dirty = snapshotOf(rows) !== savedSnapshot;
    const errorCounts = countErrorsByWeekday(
        errors,
        rows.map((row) => row.weekday),
    );
    const errorSignature = JSON.stringify(errorCounts);

    useEffect(() => {
        const first = firstWeekdayWithErrors(errorCounts, WEEKDAYS);

        if (first === null) {
            return;
        }

        const id = tabIdFor(first);

        setActiveTab(id);
        replaceTabUrl(id);
    }, [errorSignature]);

    const setRows = (next: SegmentRow[]): void => {
        form.clearErrors();
        form.setData('segments', next);
    };

    const patchRow = (key: number, patch: Partial<SegmentRow>): void => {
        setRows(
            rows.map((row) => (row.key === key ? { ...row, ...patch } : row)),
        );
    };

    const addRow = (weekday: number): void => {
        const taken = new Set(
            rows
                .filter((row) => row.weekday === weekday)
                .map((row) => row.starts_at),
        );
        const start =
            ['00:00', '06:00', '12:00', '18:00'].find(
                (time) => !taken.has(time),
            ) ?? '';

        setRows(
            sortRows([
                ...rows,
                {
                    key: nextKey++,
                    weekday,
                    starts_at: start,
                    practice: true,
                    practice_minutes: '60',
                    qualifying: false,
                    qualifying_minutes: '',
                    race: false,
                    race_duration_type: 'time',
                    race_value: '',
                    start_hour: 14,
                    weather: 'clear',
                },
            ]),
        );
    };

    const removeRow = (key: number): void => {
        setRows(rows.filter((row) => row.key !== key));
    };

    const copyDay = (weekday: number): void => {
        const source = rows.filter((row) => row.weekday === weekday);

        setRows(
            sortRows(
                WEEKDAYS.flatMap((day) =>
                    day === weekday
                        ? source
                        : source.map((row) => ({
                              ...row,
                              key: nextKey++,
                              weekday: day,
                          })),
                ),
            ),
        );
    };

    const submit = (apply: ScheduleForm['apply']): void => {
        if (rows.length === 0) {
            form.setError('segments', t('admin.schedule.errNoSegments'));

            return;
        }

        form.clearErrors();
        form.transform((data) => ({
            apply,
            segments: data.segments.map(toPayload),
        }));
        const submitted = snapshotOf(rows);

        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => {
                setSavedSnapshot(submitted);
                replaceTabUrl(activeTab);
            },
        });
    };

    const rowErrors = (index: number): string[] =>
        Object.entries(errors)
            .filter(([key]) => key.startsWith(`segments.${index}.`))
            .map(([, message]) => message as string);

    const renderDay = (weekday: number): ReactNode => {
        const dayName = t(`admin.schedule.day${weekday}`);
        const dayRows = rows.filter((row) => row.weekday === weekday);

        return (
            <section
                key={weekday}
                aria-labelledby={`schedule-day-${weekday}`}
                className={sectionClass}
            >
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2
                        id={`schedule-day-${weekday}`}
                        className={sectionTitleClass}
                    >
                        {dayName}
                    </h2>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing || dayRows.length === 0}
                            onClick={() => copyDay(weekday)}
                        >
                            <Copy aria-hidden="true" />
                            {t('admin.schedule.copyDay')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => addRow(weekday)}
                        >
                            <Plus aria-hidden="true" />
                            {t('admin.schedule.addSegment')}
                        </Button>
                    </div>
                </div>
                {dayRows.length === 0 ? (
                    <p className="text-sm text-muted">
                        {t('admin.schedule.noSegments')}
                    </p>
                ) : null}
                {dayRows.map((row, position) => {
                    const index = rows.indexOf(row);
                    const id = `segment-${row.key}`;
                    const messages = rowErrors(index);
                    const label = t('admin.schedule.segmentLabel', {
                        day: dayName,
                        n: String(position + 1),
                    });

                    return (
                        <div
                            key={row.key}
                            role="group"
                            aria-label={label}
                            className="grid gap-3 rounded-md border border-border p-3.5"
                        >
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="grid content-start gap-1.5">
                                    <label
                                        htmlFor={`${id}-start`}
                                        className="text-sm font-semibold"
                                    >
                                        {t('admin.schedule.start')}
                                    </label>
                                    <Input
                                        id={`${id}-start`}
                                        type="time"
                                        value={row.starts_at}
                                        aria-invalid={Boolean(
                                            errors[
                                                `segments.${index}.starts_at`
                                            ],
                                        )}
                                        onChange={(event) =>
                                            patchRow(row.key, {
                                                starts_at: event.target.value,
                                            })
                                        }
                                        onBlur={() =>
                                            form.setData(
                                                'segments',
                                                sortRows(rows),
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid content-start gap-1.5">
                                    <label className="flex min-h-5 items-center gap-2 text-sm font-semibold">
                                        <input
                                            type="checkbox"
                                            className={checkboxClass}
                                            checked={row.practice}
                                            onChange={(event) =>
                                                patchRow(row.key, {
                                                    practice:
                                                        event.target.checked,
                                                })
                                            }
                                        />
                                        {t('admin.schedule.practice')}
                                    </label>
                                    <Input
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        max={600}
                                        disabled={!row.practice}
                                        aria-label={t(
                                            'admin.schedule.practice',
                                        )}
                                        value={row.practice_minutes}
                                        onChange={(event) =>
                                            patchRow(row.key, {
                                                practice_minutes:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid content-start gap-1.5">
                                    <label className="flex min-h-5 items-center gap-2 text-sm font-semibold">
                                        <input
                                            type="checkbox"
                                            className={checkboxClass}
                                            checked={row.qualifying}
                                            onChange={(event) =>
                                                patchRow(row.key, {
                                                    qualifying:
                                                        event.target.checked,
                                                })
                                            }
                                        />
                                        {t('admin.schedule.qualifying')}
                                    </label>
                                    <Input
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        max={120}
                                        disabled={!row.qualifying}
                                        aria-label={t(
                                            'admin.schedule.qualifying',
                                        )}
                                        value={row.qualifying_minutes}
                                        onChange={(event) =>
                                            patchRow(row.key, {
                                                qualifying_minutes:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                    {hasRaceWithoutQualifying(
                                        row.race,
                                        row.qualifying,
                                        row.qualifying_minutes,
                                    ) ? (
                                        <p
                                            role="status"
                                            className="text-[13px] text-warn"
                                        >
                                            {t('admin.schedule.noQualyWarning')}
                                        </p>
                                    ) : null}
                                </div>
                                <div className="grid content-start gap-1.5">
                                    <label className="flex min-h-5 items-center gap-2 text-sm font-semibold">
                                        <input
                                            type="checkbox"
                                            className={checkboxClass}
                                            checked={row.race}
                                            onChange={(event) =>
                                                patchRow(row.key, {
                                                    race: event.target.checked,
                                                })
                                            }
                                        />
                                        {t('admin.schedule.race')}
                                    </label>
                                    <div className="flex gap-2">
                                        <Select
                                            value={row.race_duration_type}
                                            disabled={!row.race}
                                            onValueChange={(value) =>
                                                patchRow(row.key, {
                                                    race_duration_type:
                                                        value as RaceType,
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                aria-label={t(
                                                    'admin.schedule.race',
                                                )}
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="time">
                                                    {t(
                                                        'admin.schedule.raceMinutes',
                                                    )}
                                                </SelectItem>
                                                <SelectItem value="laps">
                                                    {t(
                                                        'admin.schedule.raceLaps',
                                                    )}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <Input
                                            type="number"
                                            inputMode="numeric"
                                            min={1}
                                            max={
                                                row.race_duration_type ===
                                                'laps'
                                                    ? 200
                                                    : 240
                                            }
                                            disabled={!row.race}
                                            aria-label={t(
                                                'admin.schedule.raceValue',
                                            )}
                                            value={row.race_value}
                                            onChange={(event) =>
                                                patchRow(row.key, {
                                                    race_value:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="grid content-start gap-1.5">
                                    <span className="text-sm font-semibold">
                                        {t('admin.schedule.gameHour')}
                                    </span>
                                    <Select
                                        value={row.start_hour.toString()}
                                        onValueChange={(value) =>
                                            patchRow(row.key, {
                                                start_hour: Number(value),
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            aria-label={t(
                                                'admin.schedule.gameHour',
                                            )}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {HOURS.map((hour) => (
                                                <SelectItem
                                                    key={hour}
                                                    value={hour.toString()}
                                                >
                                                    {formatHour(hour)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid content-start gap-1.5">
                                    <span className="text-sm font-semibold">
                                        {t('admin.schedule.weather')}
                                    </span>
                                    <Select
                                        value={row.weather}
                                        onValueChange={(value) =>
                                            patchRow(row.key, {
                                                weather: value as Weather,
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            aria-label={t(
                                                'admin.schedule.weather',
                                            )}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {WEATHERS.map((weather) => (
                                                <SelectItem
                                                    key={weather}
                                                    value={weather}
                                                >
                                                    {t(
                                                        `admin.schedule.weather_${weather}`,
                                                    )}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            {messages.map((message) => (
                                <p
                                    key={message}
                                    role="alert"
                                    className={fieldErrorClass}
                                >
                                    {message}
                                </p>
                            ))}
                            <div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    disabled={form.processing}
                                    onClick={() => removeRow(row.key)}
                                >
                                    <Trash2 aria-hidden="true" />
                                    {t('admin.schedule.removeSegment')}
                                </Button>
                            </div>
                        </div>
                    );
                })}
            </section>
        );
    };

    const summaryFor = (weekday: number) => {
        const dayRows = rows.filter((row) => row.weekday === weekday);

        return {
            count: dayRows.length,
            errorCount: errorCounts[weekday] ?? 0,
            warning: dayRows.some((row) =>
                hasRaceWithoutQualifying(
                    row.race,
                    row.qualifying,
                    row.qualifying_minutes,
                ),
            ),
        };
    };

    const tabItems: TabItem[] = WEEKDAYS.map((weekday) => {
        const summary = summaryFor(weekday);

        return {
            id: tabIdFor(weekday),
            label: t(`admin.schedule.dayShort${weekday}`),
            status:
                summary.count === 0
                    ? '—'
                    : summary.count === 1
                      ? t('admin.schedule.tabCountOne')
                      : t('admin.schedule.tabCountMany', {
                            n: String(summary.count),
                        }),
            badge: summary.errorCount,
            dot: summary.warning,
            indicatorLabel:
                summary.errorCount > 0
                    ? t('admin.schedule.tabErrors')
                    : t('admin.schedule.tabNoQualy'),
            panel: renderDay(weekday),
        };
    });

    const changeTab = (id: string): void => {
        setActiveTab(id);
        replaceTabUrl(id);
    };

    return (
        <>
            <Head title={t('admin.schedule.title')} />
            <div className="grid gap-6">
                <div className="sticky top-[calc(4rem+1px+env(safe-area-inset-top,0px))] z-20 -mx-4 grid gap-2 border-b border-border bg-bg px-4 py-3 md:-mx-8 md:px-8">
                    <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                        <div className="flex min-w-0 flex-wrap items-baseline gap-x-4 gap-y-1">
                            <h1 className="font-display text-2xl leading-tight font-bold tracking-[-0.02em]">
                                {t('admin.schedule.title')}
                            </h1>
                            {schedule === null ? null : (
                                <p
                                    role="status"
                                    className={cn(
                                        'text-sm font-medium',
                                        schedule.pending
                                            ? 'text-warn'
                                            : 'text-info',
                                    )}
                                >
                                    {t(
                                        schedule.pending
                                            ? 'admin.schedule.pending'
                                            : 'admin.schedule.current',
                                        { date: schedule.effective_from },
                                    )}
                                </p>
                            )}
                            {dirty ? (
                                <p className="text-sm text-muted">
                                    <span
                                        aria-hidden="true"
                                        className="mr-1.5 inline-block size-2 rounded-full bg-warn align-middle"
                                    />
                                    {t('admin.schedule.unsaved')}
                                </p>
                            ) : null}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing}
                                onClick={() => submit('tomorrow')}
                            >
                                {t('admin.schedule.applyTomorrow')}
                            </Button>
                            <Button
                                type="button"
                                disabled={form.processing}
                                onClick={() => setConfirming(true)}
                            >
                                {t('admin.schedule.applyNow')}
                            </Button>
                        </div>
                    </div>
                    {errors.segments || errors.apply ? (
                        <p role="alert" className={fieldErrorClass}>
                            {errors.segments ?? errors.apply}
                        </p>
                    ) : null}
                </div>
                <div>
                    <p className="max-w-[60ch] text-muted">
                        {t('admin.schedule.sub')}
                    </p>
                    <p className="mt-1 max-w-[60ch] text-sm text-muted">
                        {t('admin.schedule.help')}
                    </p>
                </div>
                <form noValidate onSubmit={(event) => event.preventDefault()}>
                    <Tabs
                        label={t('admin.schedule.tabsLabel')}
                        items={tabItems}
                        activeId={activeTab}
                        onChange={changeTab}
                    />
                </form>
            </div>
            <AlertDialog open={confirming} onOpenChange={setConfirming}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {t('admin.schedule.confirmTitle')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('admin.schedule.confirmBody')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('admin.schedule.confirmCancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction onClick={() => submit('now')}>
                            {t('admin.schedule.confirmAccept')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
