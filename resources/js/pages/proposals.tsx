import { Head, Link, router, usePage } from '@inertiajs/react';
import { CircleAlert, Info, SlidersHorizontal } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { LastVoteBlock } from '@/components/proposals/last-vote';
import { LockBlock } from '@/components/proposals/lock-block';
import { ProposalCard } from '@/components/proposals/proposal-card';
import { ProposalForm } from '@/components/proposals/proposal-form';
import {
    alertClass,
    secondaryButtonClass,
    sectionClass,
    sectionTitleClass,
    warningClass,
} from '@/components/account-styles';
import { usePublicRoutes } from '@/hooks/use-public-routes';
import { useT } from '@/hooks/use-t';
import { formatDate, formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { weekRange } from '@/lib/week-range';
import { destroy, intent, vote } from '@/routes/proposals';
import type {
    LastVote,
    ProposalItem,
    ProposalOptions,
    ProposalViewer,
    ProposalWeek,
} from '@/types/proposals';

const POLL_INTERVAL_MS = 30000;

const POLL_PROPS = ['serverNow', 'week', 'total', 'proposals', 'viewer'];

const CLOSING_DATE: Intl.DateTimeFormatOptions = {
    weekday: 'long',
    day: 'numeric',
    month: 'short',
};

function usePublicPolling(enabled: boolean): void {
    useEffect(() => {
        if (!enabled) {
            return;
        }

        const timer = setInterval(() => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            router.reload({ only: POLL_PROPS, async: true });
        }, POLL_INTERVAL_MS);

        return () => clearInterval(timer);
    }, [enabled]);
}

export default function Proposals({
    week,
    total,
    proposals,
    viewer,
    options,
    lastVote,
}: {
    week: ProposalWeek | null;
    total: number;
    proposals: ProposalItem[];
    viewer: ProposalViewer;
    options: ProposalOptions;
    lastVote: LastVote | null;
}) {
    const t = useT();
    const { proposalsCreate } = usePublicRoutes();
    const { locale } = usePage<{ locale: string }>().props;
    const [processing, setProcessing] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const isOpen = week?.isOpen === true;
    const maxVotes = Math.max(0, ...proposals.map((item) => item.votes));

    usePublicPolling(isOpen);

    const visit = (
        send: (callbacks: {
            preserveScroll: boolean;
            onStart: () => void;
            onFinish: () => void;
            onSuccess: () => void;
            onError: (errors: Record<string, string>) => void;
        }) => void,
    ): void => {
        send({
            preserveScroll: true,
            onStart: () => {
                setProcessing(true);
                setActionError(null);
            },
            onFinish: () => setProcessing(false),
            onSuccess: () => setActionError(null),
            onError: (errors) =>
                setActionError(
                    errors.vote ?? errors.proposal ?? Object.values(errors)[0],
                ),
        });
    };

    const onVote = (proposal: ProposalItem): void => {
        const route =
            viewer.state === 'guest' ? intent(proposal.id) : vote(proposal.id);

        visit((options) => router.post(route.url, {}, options));
    };

    const onWithdraw = (proposal: ProposalItem): void => {
        if (!window.confirm(t('props.withdrawConfirm'))) {
            return;
        }

        visit((options) => router.delete(destroy(proposal.id).url, options));
    };

    const closes = week ? new Date(week.votingClosesAt) : null;

    return (
        <>
            <Head title={t('props.title')} />
            <div className="grid gap-6">
                <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                    <div>
                        <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                            {t('props.title')}
                        </h1>
                        {week ? (
                            <p className="mt-2 text-muted">
                                {t('props.sub', {
                                    range: weekRange(week, locale),
                                })}
                            </p>
                        ) : null}
                    </div>
                    {isOpen ? (
                        <Link
                            href={proposalsCreate().url}
                            className={secondaryButtonClass}
                        >
                            <SlidersHorizontal
                                className="size-[18px]"
                                aria-hidden="true"
                            />
                            {t('props.newLink')}
                        </Link>
                    ) : null}
                </div>

                {week && closes ? (
                    <div className="flex flex-wrap items-center justify-between gap-x-7 gap-y-2 rounded-md border border-border bg-surface px-4.5 py-4">
                        <h2 className="text-[15px] font-semibold">
                            {t('props.closes')}
                        </h2>
                        <span className="mono">
                            {t('props.closesAt', {
                                date: formatDate(closes, locale, CLOSING_DATE),
                                time: formatTime(closes, locale),
                                zone: week.zone,
                            })}
                        </span>
                    </div>
                ) : null}

                {isOpen ? (
                    <p className="flex items-start gap-2.5 text-sm text-muted">
                        <Info
                            className="mt-0.5 size-4 flex-none"
                            aria-hidden="true"
                        />
                        {t('props.rule')}
                    </p>
                ) : null}

                {viewer.vote?.hidden ? (
                    <p role="status" className={warningClass}>
                        <span className="flex items-center gap-2.5">
                            <CircleAlert
                                className="size-[18px] flex-none"
                                aria-hidden="true"
                            />
                            {t('props.hiddenVote')}
                        </span>
                    </p>
                ) : null}

                {actionError ? (
                    <p
                        role="alert"
                        className={cn(alertClass, 'flex items-center gap-2.5')}
                    >
                        <CircleAlert
                            className="size-[18px] flex-none"
                            aria-hidden="true"
                        />
                        {actionError}
                    </p>
                ) : null}

                {week === null && lastVote ? (
                    <p role="status" className="text-muted">
                        {t('props.closed')}
                    </p>
                ) : (
                    <div
                        className={cn(
                            'grid gap-6',
                            isOpen &&
                                'lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] lg:items-start',
                        )}
                    >
                        <section aria-labelledby="proposals-list-title">
                            <div className="mb-3.5 flex flex-wrap items-baseline justify-between gap-3">
                                <h2
                                    id="proposals-list-title"
                                    className={sectionTitleClass}
                                >
                                    {t('props.list')}
                                </h2>
                                <span className="mono text-[13px] text-muted">
                                    {total} {t('props.total')}
                                </span>
                            </div>
                            {week === null ? (
                                <p className="text-muted">
                                    {t('props.noWeek')}
                                </p>
                            ) : proposals.length === 0 ? (
                                <div className="grid gap-3">
                                    <EmptyState />
                                    <p className="text-sm text-muted">
                                        {t('props.empty')}
                                    </p>
                                </div>
                            ) : (
                                <ol className="grid gap-2.5">
                                    {proposals.map((proposal) => (
                                        <ProposalCard
                                            key={proposal.id}
                                            proposal={proposal}
                                            maxVotes={maxVotes}
                                            viewer={viewer}
                                            canAct={isOpen}
                                            processing={processing}
                                            onVote={onVote}
                                            onWithdraw={onWithdraw}
                                        />
                                    ))}
                                </ol>
                            )}
                            {week !== null && !isOpen ? (
                                <p role="status" className="mt-3.5 text-muted">
                                    {t('props.closed')}
                                </p>
                            ) : null}
                        </section>

                        {isOpen ? (
                            <section
                                aria-labelledby="proposals-form-title"
                                className={cn(
                                    sectionClass,
                                    'lg:sticky lg:top-24',
                                )}
                            >
                                <h2
                                    id="proposals-form-title"
                                    className={sectionTitleClass}
                                >
                                    {t('props.form')}
                                </h2>
                                {viewer.state !== 'ready' ? (
                                    <LockBlock state={viewer.state} />
                                ) : viewer.proposalLimitReached ? (
                                    <p role="status" className="text-muted">
                                        {t('props.quota')}
                                    </p>
                                ) : (
                                    <>
                                        <ProposalForm options={options} />
                                        <Link
                                            href={proposalsCreate().url}
                                            className="justify-self-start text-sm font-semibold text-primary-ink underline underline-offset-4"
                                        >
                                            {t('props.moreFilters')}
                                        </Link>
                                    </>
                                )}
                            </section>
                        ) : null}
                    </div>
                )}

                {lastVote ? <LastVoteBlock lastVote={lastVote} /> : null}
            </div>
        </>
    );
}
