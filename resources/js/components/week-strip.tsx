import { useT } from '@/hooks/use-t';
import { formatDate, formatTime } from '@/lib/datetime';
import { segmentDetail, segmentTitle } from '@/lib/schedule';
import type { ScheduleDay } from '@/lib/schedule';
import { styleRef } from '@/lib/style-ref';
import { cn } from '@/lib/utils';

type WeekStripProps = {
    days: ScheduleDay[];
    zone: string;
    serverNow: string;
    locale: string;
    pending?: boolean;
};

const DAY_MS = 24 * 60 * 60 * 1000;

function dayProgress(day: ScheduleDay, serverNow: string): number {
    if (day.isPast) {
        return 100;
    }

    if (!day.isToday) {
        return 0;
    }

    const start = new Date(day.date).getTime();

    return Math.min(
        100,
        Math.max(0, ((new Date(serverNow).getTime() - start) / DAY_MS) * 100),
    );
}

export function WeekStrip({
    days,
    zone,
    serverNow,
    locale,
    pending = false,
}: WeekStripProps) {
    const t = useT();

    return (
        <div
            role="list"
            className="grid grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))] gap-2"
        >
            {days.map((day) => {
                const progress = dayProgress(day, serverNow);
                const date = new Date(day.date);

                return (
                    <div
                        key={day.key}
                        role="listitem"
                        aria-current={day.isToday ? 'date' : undefined}
                        className={cn(
                            'relative flex min-h-44 min-w-0 flex-col gap-2 rounded-md border bg-surface p-3.5 text-left',
                            day.isPast && 'opacity-80',
                            day.hasRace && 'border-primary bg-tint',
                            day.isToday && 'border-fg',
                            pending && 'border-dashed',
                        )}
                    >
                        <div>
                            <div
                                className={cn(
                                    'font-display text-[22px] leading-none font-bold',
                                    day.hasRace && 'text-primary-ink',
                                )}
                            >
                                {t(`strip.dayNames.${day.key}`)}
                            </div>
                            <div className="mono text-xs text-muted">
                                {formatDate(date, locale, {
                                    day: 'numeric',
                                    month: 'short',
                                })}
                            </div>
                            {day.isToday ? (
                                <div className="mt-1 block w-fit rounded-xs bg-fg px-1.5 py-0.5 mono text-xs font-medium text-bg">
                                    {t('cal.today')} · {t('strip.now')}{' '}
                                    {formatTime(new Date(serverNow), locale)}
                                </div>
                            ) : null}
                        </div>
                        <ul className="grid gap-2">
                            {day.segments.map((segment) => (
                                <li
                                    key={segment.startsAt}
                                    aria-current={
                                        segment.status === 'now'
                                            ? 'time'
                                            : undefined
                                    }
                                    className={cn(
                                        'grid gap-0.5 rounded-sm border border-transparent px-1.5 py-1 text-[13px] leading-tight',
                                        segment.status === 'now' &&
                                            'border-primary bg-surface',
                                    )}
                                >
                                    <span className="mono text-xs font-medium text-muted">
                                        {t('cal.hours', {
                                            from: formatTime(
                                                new Date(segment.startsAt),
                                                locale,
                                            ),
                                            to: formatTime(
                                                new Date(segment.endsAt),
                                                locale,
                                            ),
                                            zone,
                                        })}
                                    </span>
                                    <span
                                        className={cn(
                                            segment.isRace
                                                ? 'font-semibold text-fg'
                                                : 'text-muted',
                                        )}
                                    >
                                        {segmentTitle(segment, t)}
                                    </span>
                                    <span className="text-xs text-muted">
                                        {segmentDetail(segment, t)}
                                    </span>
                                    {segment.status === 'now' ? (
                                        <span className="text-xs font-semibold text-primary-ink">
                                            {t('cal.nowAt')}
                                        </span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                        <div
                            aria-hidden="true"
                            className="relative mt-auto h-1 overflow-hidden rounded-xs bg-surface-2"
                        >
                            <i
                                className="absolute inset-y-0 left-0 bg-border-strong"
                                ref={styleRef({ width: `${progress}%` })}
                            />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
