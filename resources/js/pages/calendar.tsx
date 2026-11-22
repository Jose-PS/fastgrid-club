import { Head, Link, usePage } from '@inertiajs/react';
import { Info, Vote } from 'lucide-react';
import { useState } from 'react';
import { WeekStrip } from '@/components/week-strip';
import { usePublicRoutes } from '@/hooks/use-public-routes';
import { useT } from '@/hooks/use-t';
import { formatDate, formatDateTime } from '@/lib/datetime';
import type { ScheduleDay } from '@/lib/schedule';
import { cn } from '@/lib/utils';

type CalendarWeek = {
    key: 'current' | 'next';
    startsAt: string;
    endsAt: string;
    zone: string;
    status: 'voting' | 'scheduled' | 'active' | 'finished' | null;
    votingClosesAt: string;
    configuration: { track: string; cars: string[] } | null;
    days: ScheduleDay[];
};

function range(week: CalendarWeek, locale: string): string {
    const options: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
    };

    return `${formatDate(new Date(week.days[0].date), locale, options)} – ${formatDate(new Date(week.days[6].date), locale, options)}`;
}

export default function Calendar({
    weeks,
    serverNow,
}: {
    weeks: [CalendarWeek, CalendarWeek];
    serverNow: string;
}) {
    const t = useT();
    const { proposals } = usePublicRoutes();
    const { locale } = usePage<{ locale: string }>().props;
    const [selected, setSelected] = useState(0);
    const week = weeks[selected];
    const isPending = week.key === 'next' && week.status !== 'scheduled';
    const isVotable =
        week.status === 'voting' ||
        (week.key === 'next' && week.status === null);

    return (
        <>
            <Head title={t('cal.title')} />
            <div className="grid gap-7">
                <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
                    <div>
                        <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                            {t('cal.title')}
                        </h1>
                        <p className="mt-2 text-muted">
                            {t('cal.sub', { zone: week.zone })}
                        </p>
                    </div>
                    <div
                        role="group"
                        aria-label={t('cal.week')}
                        className="inline-flex flex-wrap rounded-sm border border-border bg-surface p-0.5"
                    >
                        {weeks.map((item, index) => (
                            <button
                                key={item.key}
                                type="button"
                                aria-pressed={selected === index}
                                onClick={() => setSelected(index)}
                                className={cn(
                                    'min-h-11 rounded-xs px-3.5 text-sm font-semibold text-muted transition-colors duration-150 ease-out hover:text-fg',
                                    selected === index &&
                                        'bg-surface-2 text-fg shadow-[inset_0_0_0_1px_var(--border-strong)]',
                                )}
                            >
                                {item.key === 'current'
                                    ? t('cal.thisWeek')
                                    : t('cal.nextWeek')}
                                <span className="ml-1.5 mono text-xs">
                                    {range(item, locale)}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>

                {isPending ? (
                    <div
                        role="status"
                        className="flex flex-wrap items-center gap-2.5 rounded-md border border-info/45 px-3.5 py-3 font-medium text-info"
                    >
                        <Info
                            className="size-[18px] flex-none"
                            aria-hidden="true"
                        />
                        <span>
                            {t('cal.pending', {
                                deadline: formatDateTime(
                                    new Date(week.votingClosesAt),
                                    locale,
                                ),
                            })}
                        </span>
                        <Link
                            href={proposals().url}
                            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border border-border-strong px-3 text-sm font-semibold text-fg transition-colors duration-150 ease-out hover:bg-surface-2"
                        >
                            <Vote className="size-4" aria-hidden="true" />
                            {t('cal.goVote')}
                        </Link>
                    </div>
                ) : null}

                <WeekStrip
                    days={week.days}
                    zone={week.zone}
                    serverNow={serverNow}
                    locale={locale}
                    pending={isPending}
                />

                <section
                    aria-labelledby="config-title"
                    className="mt-2 grid gap-4"
                >
                    <h2
                        id="config-title"
                        className="font-display text-xl leading-tight font-bold tracking-[-0.01em]"
                    >
                        {t('week.config')}
                    </h2>

                    {week.configuration ? (
                        <dl className="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-xs font-semibold tracking-[0.06em] text-muted uppercase">
                                    {t('week.circuit')}
                                </dt>
                                <dd className="font-display text-lg font-bold">
                                    {week.configuration.track}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-semibold tracking-[0.06em] text-muted uppercase">
                                    {t('week.cars')}
                                </dt>
                                <dd className="flex flex-wrap gap-1.5">
                                    {week.configuration.cars.map((car) => (
                                        <span
                                            key={car}
                                            className="rounded-sm border border-border-strong px-2 py-0.5"
                                        >
                                            {car}
                                        </span>
                                    ))}
                                </dd>
                            </div>
                        </dl>
                    ) : (
                        <p className="text-sm text-muted">
                            {t(isVotable ? 'cal.pendingCfg' : 'cal.tba')}
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}
