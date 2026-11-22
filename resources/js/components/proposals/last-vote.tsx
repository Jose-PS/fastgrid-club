import { usePage } from '@inertiajs/react';
import { Lock, Trophy } from 'lucide-react';
import { CarLabel } from '@/components/car-label';
import { sectionClass, sectionTitleClass } from '@/components/account-styles';
import { useT } from '@/hooks/use-t';
import { votePercentage } from '@/lib/proposal-form';
import { styleRef } from '@/lib/style-ref';
import { cn } from '@/lib/utils';
import { weekRange } from '@/lib/week-range';
import type { LastVote, ProposalCar } from '@/types/proposals';

function CarChips({ cars }: { cars: ProposalCar[] }) {
    return (
        <ul className="mt-2 flex flex-wrap gap-1.5">
            {cars.map((car) => (
                <li
                    key={car.id}
                    className="rounded-sm border border-border-strong px-2 py-0.5 text-sm"
                >
                    <CarLabel name={car.displayName} />
                </li>
            ))}
        </ul>
    );
}

export function LastVoteBlock({ lastVote }: { lastVote: LastVote }) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const maxVotes = Math.max(0, ...lastVote.ranking.map((item) => item.votes));
    const winner =
        lastVote.winnerId !== null
            ? (lastVote.ranking.find((item) => item.id === lastVote.winnerId) ??
              null)
            : null;
    const reason =
        lastVote.source === 'fallback'
            ? lastVote.config
                ? t('props.lastDefault')
                : t('props.lastFallback')
            : lastVote.source === 'admin'
              ? t('props.lastAdmin')
              : null;

    return (
        <section
            aria-labelledby="last-vote-title"
            className={cn(sectionClass, 'gap-5')}
        >
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                <div>
                    <h2 id="last-vote-title" className={sectionTitleClass}>
                        {t('props.lastTitle')}
                    </h2>
                    <p className="mt-1 text-sm text-muted">
                        {t('props.lastWeek', {
                            range: weekRange(lastVote.week, locale),
                        })}
                    </p>
                </div>
                <span
                    role="status"
                    className="inline-flex items-center gap-2 rounded-sm border border-border-strong px-2.5 py-1 text-[13px] font-semibold text-muted"
                >
                    <Lock className="size-3.5" aria-hidden="true" />
                    {t('props.lastClosed')}
                </span>
            </div>

            {winner ? (
                <div className="grid gap-1 rounded-md border border-primary bg-tint p-4">
                    <p className="flex items-center gap-2 text-[13px] font-semibold text-primary-ink">
                        <Trophy className="size-4" aria-hidden="true" />
                        {t('props.lastWinner')}
                    </p>
                    <h3 className="font-display text-xl leading-tight font-bold tracking-[-0.015em]">
                        {winner.track.display}
                        <span className="mt-1 block text-xs font-normal tracking-normal text-muted">
                            {t('props.pits', { n: winner.track.maxPitSlot })}
                        </span>
                    </h3>
                    <CarChips cars={winner.cars} />
                    <p className="mt-2 mono text-[15px]">
                        <b>{winner.votes}</b> {t('props.votes')}
                    </p>
                </div>
            ) : reason ? (
                <div className="grid gap-1 rounded-md border border-border-strong bg-surface-2 p-4">
                    <p className="text-sm font-semibold">{reason}</p>
                    {lastVote.config ? (
                        <>
                            <h3 className="mt-2 font-display text-xl leading-tight font-bold tracking-[-0.015em]">
                                {lastVote.config.track.display}
                                <span className="mt-1 block text-xs font-normal tracking-normal text-muted">
                                    {t('props.pits', {
                                        n: lastVote.config.track.maxPitSlot,
                                    })}
                                </span>
                            </h3>
                            <CarChips cars={lastVote.config.cars} />
                        </>
                    ) : null}
                </div>
            ) : null}

            <div className="grid gap-3">
                <h3 className="text-[15px] font-semibold">
                    {t('props.lastRanking')}
                </h3>
                {lastVote.ranking.length === 0 ? (
                    <p className="text-sm text-muted">{t('props.lastEmpty')}</p>
                ) : (
                    <ol className="grid gap-2.5">
                        {lastVote.ranking.map((item) => {
                            const isWinner = item.id === winner?.id;

                            return (
                                <li
                                    key={item.id}
                                    className={cn(
                                        'grid grid-cols-[28px_minmax(0,1fr)] gap-x-3 gap-y-2 rounded-md border border-border bg-surface p-3.5',
                                        isWinner && 'border-primary',
                                    )}
                                >
                                    <div
                                        role="img"
                                        aria-label={`${t('props.rank')} ${item.rank}`}
                                        className="font-display text-lg leading-none font-bold text-muted"
                                    >
                                        {item.rank}
                                    </div>
                                    <div>
                                        <h4 className="font-semibold">
                                            {item.track.display}
                                        </h4>
                                        <CarChips cars={item.cars} />
                                    </div>
                                    <div className="col-start-2 flex items-center gap-2.5">
                                        <div
                                            role="progressbar"
                                            aria-label={t('props.votes')}
                                            aria-valuemin={0}
                                            aria-valuemax={maxVotes}
                                            aria-valuenow={item.votes}
                                            className="h-2 flex-1 overflow-hidden rounded-xs border border-border bg-surface-2"
                                        >
                                            <i
                                                className={cn(
                                                    'block h-full bg-fg opacity-85',
                                                    isWinner &&
                                                        'bg-primary opacity-100',
                                                )}
                                                ref={styleRef({
                                                    width: `${votePercentage(item.votes, maxVotes)}%`,
                                                })}
                                            />
                                        </div>
                                        <b className="min-w-[2ch] text-right mono text-[15px]">
                                            {item.votes}
                                        </b>
                                        <span className="sr-only">
                                            {t('props.votes')}
                                        </span>
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                )}
            </div>
        </section>
    );
}
