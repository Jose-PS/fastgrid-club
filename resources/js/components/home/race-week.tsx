import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { WeekStrip } from '@/components/week-strip';
import { useClock } from '@/hooks/use-clock';
import { useT } from '@/hooks/use-t';
import { formatCountdownUnit, getCountdown } from '@/lib/countdown';
import { formatDateTime } from '@/lib/datetime';
import type { ScheduleDay } from '@/lib/schedule';

export function RaceWeek({
    days,
    zone,
    raceInProgress,
    nextRaceStart,
    offsetMs,
    locale,
}: {
    days: ScheduleDay[];
    zone: string;
    raceInProgress: boolean;
    nextRaceStart: string | null;
    offsetMs: number;
    locale: string;
}) {
    const t = useT();
    const clientNow = useClock();
    const reloadedFor = useRef<string | null>(null);
    const currentEnd =
        days
            .flatMap((day) => day.segments)
            .find((segment) => segment.status === 'now')?.endsAt ?? null;
    const reloadTarget = raceInProgress ? currentEnd : nextRaceStart;
    const target = nextRaceStart === null ? null : new Date(nextRaceStart);
    const parts = getCountdown(target?.getTime() ?? 0, clientNow, offsetMs);
    const reloadParts = getCountdown(
        reloadTarget === null ? 0 : new Date(reloadTarget).getTime(),
        clientNow,
        offsetMs,
    );
    const label = t('strip.cd');
    const cells = [
        [parts.days, 'strip.d'],
        [parts.hours, 'strip.h'],
        [parts.minutes, 'strip.m'],
        [parts.seconds, 'strip.s'],
    ] as const;

    useEffect(() => {
        if (
            reloadTarget !== null &&
            reloadParts.done &&
            reloadedFor.current !== reloadTarget
        ) {
            reloadedFor.current = reloadTarget;
            router.reload({ only: ['week', 'status', 'serverNow'] });
        }
    }, [reloadParts.done, reloadTarget]);

    return (
        <div className="grid gap-5.5">
            {raceInProgress ? (
                <div
                    role="status"
                    className="flex flex-wrap items-center gap-2.5 rounded-md border border-primary bg-tint px-4.5 py-4 font-semibold text-primary-ink"
                >
                    {t('strip.raceNow')}
                </div>
            ) : target !== null ? (
                <div className="flex flex-wrap items-center justify-between gap-x-7 gap-y-4 rounded-md border border-border bg-surface px-4.5 py-4">
                    <div className="grid gap-1">
                        <span className="text-xs font-semibold tracking-[0.07em] text-muted uppercase">
                            {label}
                        </span>
                        <span className="font-medium">
                            {formatDateTime(target, locale)}
                        </span>
                    </div>
                    <div
                        role="timer"
                        aria-live="off"
                        aria-label={label}
                        className="flex flex-wrap items-stretch gap-2"
                    >
                        {cells.map(([value, key]) => (
                            <div
                                key={key}
                                className="min-w-16 rounded-md border border-border bg-bg px-2.5 py-2 text-center"
                            >
                                <b
                                    suppressHydrationWarning
                                    className="block mono text-[26px] leading-[1.1] tracking-[-0.02em]"
                                >
                                    {formatCountdownUnit(value)}
                                </b>
                                <span className="text-xs font-semibold tracking-[0.06em] text-muted uppercase">
                                    {t(key)}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            ) : (
                <p className="rounded-md border border-border bg-surface px-4.5 py-4 text-muted">
                    {t('strip.noRaces')}
                </p>
            )}
            <WeekStrip
                days={days}
                zone={zone}
                serverNow={new Date(clientNow + offsetMs).toISOString()}
                locale={locale}
            />
        </div>
    );
}
