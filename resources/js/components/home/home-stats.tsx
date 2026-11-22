import { lazy, Suspense } from 'react';
import type { ComponentType } from 'react';
import type { BestLaps as BestLapsView } from '@/components/home/best-laps';
import type { LastRaceDay as LastRaceDayView } from '@/components/home/last-race-day';
import { Skeleton } from '@/components/skeleton';

type BestLapsProps = Parameters<typeof BestLapsView>[0];
type LastRaceDayProps = Parameters<typeof LastRaceDayView>[0];

const serverBestLaps = import.meta.env.SSR
    ? (await import('@/components/home/best-laps')).BestLaps
    : null;

const serverLastRaceDay = import.meta.env.SSR
    ? (await import('@/components/home/last-race-day')).LastRaceDay
    : null;

const BestLapsComponent: ComponentType<BestLapsProps> =
    serverBestLaps ??
    lazy(() =>
        import('@/components/home/best-laps').then((module) => ({
            default: module.BestLaps,
        })),
    );

const LastRaceDayComponent: ComponentType<LastRaceDayProps> =
    serverLastRaceDay ??
    lazy(() =>
        import('@/components/home/last-race-day').then((module) => ({
            default: module.LastRaceDay,
        })),
    );

export function BestLaps(props: BestLapsProps) {
    return (
        <Suspense fallback={<Skeleton className="h-72" />}>
            <BestLapsComponent {...props} />
        </Suspense>
    );
}

export function LastRaceDay(props: LastRaceDayProps) {
    return (
        <Suspense fallback={<Skeleton className="h-72" />}>
            <LastRaceDayComponent {...props} />
        </Suspense>
    );
}
