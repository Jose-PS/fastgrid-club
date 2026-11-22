import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import { DonationBlock } from '@/components/donations/donation-block';
import { DiscordInvite } from '@/components/home/discord-invite';
import { BestLaps, LastRaceDay } from '@/components/home/home-stats';
import { HomeSection } from '@/components/home/home-section';
import { RaceWeek } from '@/components/home/race-week';
import { ServerStatusCard } from '@/components/home/server-status-card';
import { WeekConfiguration } from '@/components/home/week-configuration';
import type { WeekConfiguration as WeekConfigurationData } from '@/components/home/week-configuration';
import { TrackDiagram, TrackSvgFigure } from '@/components/track-diagram';
import type { TrackSvgData } from '@/components/track-diagram';
import { useServerStatus } from '@/hooks/use-server-status';
import { usePublicRoutes } from '@/hooks/use-public-routes';
import { useT } from '@/hooks/use-t';
import { clockOffset } from '@/lib/countdown';
import { formatNumber } from '@/lib/datetime';
import type { Donation } from '@/lib/donation';
import type {
    BestLaps as BestLapsData,
    LastRaceDay as LastRaceDayData,
} from '@/lib/home-stats';
import type { ScheduleDay } from '@/lib/schedule';
import type { ServerStatus } from '@/lib/server-status';
import { weekRange } from '@/lib/week-range';

type HomeWeek = {
    startsAt: string;
    endsAt: string;
    zone: string;
    days: ScheduleDay[];
    configuration: WeekConfigurationData | null;
    raceInProgress: boolean;
    nextRaceStart: string | null;
};

export default function Welcome({
    status,
    week,
    trackSvg,
    donation,
    bestLaps,
    lastRaceDay,
}: {
    status: ServerStatus;
    week: HomeWeek;
    trackSvg: TrackSvgData | null;
    donation: Donation | null;
    bestLaps: BestLapsData;
    lastRaceDay: LastRaceDayData;
}) {
    const { calendar } = usePublicRoutes();
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const live = useServerStatus(status);
    const offsetMs = clockOffset(
        new Date(live.status.serverNow).getTime(),
        live.receivedAtMs,
    );
    const lengthM = week.configuration?.lengthM ?? null;

    return (
        <>
            <Head title={`FastGrid - ${t('hero.h1')}`} />
            <div className="grid gap-14">
                <section
                    aria-labelledby="hero-title"
                    className="relative overflow-hidden rounded-lg border border-border bg-surface px-4.5 py-5.5 md:p-10"
                >
                    <div
                        aria-hidden="true"
                        className="hero-grid-lines pointer-events-none absolute inset-0"
                    />
                    <div className="relative grid gap-7 lg:grid-cols-[1.05fr_0.95fr] lg:items-start lg:gap-10">
                        <div className="grid gap-5">
                            <div>
                                <p className="mb-3.5 text-[13px] font-semibold tracking-[0.02em] text-muted">
                                    {t('hero.kicker')}
                                </p>
                                <h1
                                    id="hero-title"
                                    className="mb-3.5 font-display text-[clamp(2.25rem,7.4vw,3.75rem)] leading-[1.02] font-bold tracking-[-0.035em] text-balance"
                                >
                                    {t('hero.h1')}
                                </h1>
                                <p className="max-w-[46ch] text-muted">
                                    {t('hero.lede')}
                                </p>
                            </div>
                            <ServerStatusCard
                                status={live.status}
                                cars={week.configuration?.cars ?? []}
                                locale={locale}
                            />
                        </div>
                        <div className="grid gap-2 rounded-md border border-border bg-bg p-4">
                            {trackSvg !== null ? (
                                <TrackSvgFigure
                                    data={trackSvg}
                                    label={t('status.trackAria', {
                                        track: live.status.track ?? '',
                                    })}
                                />
                            ) : (
                                <TrackDiagram />
                            )}
                            <div className="font-display text-[22px] leading-tight font-bold tracking-[-0.015em]">
                                {live.status.track ?? t('cal.tba')}
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {lengthM !== null ? (
                                    <span className="rounded-sm border border-border px-2 py-0.5 mono text-[13px]">
                                        {formatNumber(lengthM / 1000, locale, {
                                            maximumFractionDigits: 1,
                                        })}{' '}
                                        km
                                    </span>
                                ) : null}
                                <span className="rounded-sm border border-border px-2 py-0.5 text-[13px] text-muted">
                                    {t('status.schematic')}
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <HomeSection
                    id="week-title"
                    title={t('week.title')}
                    meta={
                        <span className="mono">{weekRange(week, locale)}</span>
                    }
                >
                    <WeekConfiguration configuration={week.configuration} />
                </HomeSection>

                <HomeSection
                    id="strip-title"
                    title={t('strip.title')}
                    action={
                        <Link
                            href={calendar().url}
                            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border border-border-strong px-3 text-sm font-semibold text-fg transition-colors duration-150 ease-out hover:bg-surface-2"
                        >
                            <CalendarDays
                                className="size-4"
                                aria-hidden="true"
                            />
                            {t('strip.seeCal')}
                        </Link>
                    }
                >
                    <RaceWeek
                        days={week.days}
                        zone={week.zone}
                        raceInProgress={week.raceInProgress}
                        nextRaceStart={week.nextRaceStart}
                        offsetMs={offsetMs}
                        locale={locale}
                    />
                </HomeSection>

                <HomeSection
                    id="laps-title"
                    title={t('lb.title')}
                    meta={t('lb.note')}
                >
                    <BestLaps
                        data={bestLaps}
                        titleId="laps-title"
                        locale={locale}
                    />
                </HomeSection>

                <HomeSection id="results-title" title={t('res.title')}>
                    <LastRaceDay
                        data={lastRaceDay}
                        titleId="results-title"
                        locale={locale}
                    />
                </HomeSection>

                <DiscordInvite />

                {donation !== null ? (
                    <DonationBlock donation={donation} />
                ) : null}
            </div>
        </>
    );
}
