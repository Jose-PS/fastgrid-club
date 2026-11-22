import { Check, Vote } from 'lucide-react';
import { CarLabel } from '@/components/car-label';
import { useT } from '@/hooks/use-t';
import { votePercentage } from '@/lib/proposal-form';
import { styleRef } from '@/lib/style-ref';
import { cn } from '@/lib/utils';
import type { ProposalItem, ProposalViewer } from '@/types/proposals';
import { secondaryButtonClass } from '../account-styles';

export function ProposalCard({
    proposal,
    maxVotes,
    viewer,
    canAct,
    processing,
    onVote,
    onWithdraw,
}: {
    proposal: ProposalItem;
    maxVotes: number;
    viewer: ProposalViewer;
    canAct: boolean;
    processing: boolean;
    onVote: (proposal: ProposalItem) => void;
    onWithdraw: (proposal: ProposalItem) => void;
}) {
    const t = useT();
    const isVoted =
        viewer.vote !== null &&
        !viewer.vote.hidden &&
        viewer.vote.proposalId === proposal.id;
    const hasActiveVote = viewer.vote !== null && !viewer.vote.hidden;
    const canVote = canAct && viewer.state !== 'needs_identity';
    const percentage = votePercentage(proposal.votes, maxVotes);
    const titleId = `proposal-${proposal.id}-title`;

    return (
        <li
            aria-labelledby={titleId}
            className={cn(
                'grid grid-cols-[36px_minmax(0,1fr)] gap-x-4 gap-y-3 rounded-md border border-border bg-surface p-4 transition-colors duration-150 ease-out',
                isVoted && 'border-primary bg-tint',
            )}
        >
            <div
                role="img"
                aria-label={`${t('props.rank')} ${proposal.rank}`}
                className="pt-0.5 font-display text-[22px] leading-none font-bold text-muted"
            >
                {proposal.rank}
            </div>
            <div>
                <h3
                    id={titleId}
                    className="font-display text-xl leading-tight font-bold tracking-[-0.015em]"
                >
                    {proposal.track.display}
                    <span className="mt-1 block text-xs font-normal tracking-normal text-muted">
                        {t('props.pits', { n: proposal.track.maxPitSlot })}
                    </span>
                </h3>
                <ul className="mt-2 flex flex-wrap gap-1.5">
                    {proposal.cars.map((car) => (
                        <li
                            key={car.id}
                            className="rounded-sm border border-border-strong px-2 py-0.5 text-sm"
                        >
                            <CarLabel name={car.displayName} />
                        </li>
                    ))}
                </ul>
                <p className="mt-2 text-[13px] text-muted">
                    {t('props.by')} {proposal.author ?? t('props.deletedUser')}
                </p>
            </div>
            <div className="col-start-2 flex flex-wrap items-center gap-x-4 gap-y-3">
                <div className="flex min-w-30 flex-[1_1_140px] items-center gap-2.5">
                    <div
                        role="progressbar"
                        aria-label={t('props.votes')}
                        aria-valuemin={0}
                        aria-valuemax={maxVotes}
                        aria-valuenow={proposal.votes}
                        className="h-2 flex-1 overflow-hidden rounded-xs border border-border bg-surface-2"
                    >
                        <i
                            className={cn(
                                'block h-full bg-fg opacity-85',
                                isVoted && 'bg-primary opacity-100',
                            )}
                            ref={styleRef({ width: `${percentage}%` })}
                        />
                    </div>
                    <b className="min-w-[2ch] text-right mono text-[15px]">
                        {proposal.votes}
                    </b>
                    <span className="sr-only">{t('props.votes')}</span>
                </div>
                {isVoted ? (
                    <span className="inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border border-primary bg-primary px-3.5 text-sm font-semibold text-on-primary">
                        <Check className="size-4" aria-hidden="true" />
                        {t('props.yours')}
                    </span>
                ) : canVote ? (
                    <button
                        type="button"
                        disabled={processing}
                        onClick={() => onVote(proposal)}
                        className={secondaryButtonClass}
                    >
                        <Vote className="size-4" aria-hidden="true" />
                        {hasActiveVote ? t('props.move') : t('props.vote')}
                    </button>
                ) : null}
                {proposal.canWithdraw ? (
                    <button
                        type="button"
                        disabled={processing}
                        onClick={() => onWithdraw(proposal)}
                        className="inline-flex min-h-11 items-center justify-center rounded-sm px-3 text-sm font-semibold text-err underline-offset-2 hover:underline disabled:opacity-55"
                    >
                        {t('props.withdraw')}
                    </button>
                ) : null}
            </div>
        </li>
    );
}
