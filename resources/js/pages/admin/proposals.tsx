import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import {
    dangerButtonClass,
    fieldErrorClass,
    inputClass,
    secondaryButtonClass,
    sectionClass,
} from '@/components/account-styles';
import { useT } from '@/hooks/use-t';
import { formatDate } from '@/lib/datetime';
import { showToast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { hide, show } from '@/routes/admin/proposals';

type AdminProposal = {
    id: number;
    track: string;
    cars: string[];
    author: { id: number; name: string } | null;
    votes: number;
    hidden: boolean;
    hidden_reason: string | null;
};

function ProposalRow({ proposal }: { proposal: AdminProposal }) {
    const t = useT();
    const form = useForm<{ reason: string }>({ reason: '' });
    const reasonError = (form.errors as Record<string, string | undefined>)
        .reason;
    const proposalError = (form.errors as Record<string, string | undefined>)
        .proposal;

    const submitHide = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (form.data.reason.trim() === '') {
            form.setError('reason', t('admin.proposals.errReason'));

            return;
        }

        if (!window.confirm(t('admin.proposals.confirmHide'))) {
            return;
        }

        form.clearErrors();
        form.post(hide(proposal.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                showToast({
                    type: 'success',
                    message: t('admin.proposals.hidden'),
                });
            },
        });
    };

    const submitShow = (): void => {
        if (!window.confirm(t('admin.proposals.confirmShow'))) {
            return;
        }

        form.post(show(proposal.id).url, {
            preserveScroll: true,
            onSuccess: () =>
                showToast({
                    type: 'success',
                    message: t('admin.proposals.shown'),
                }),
        });
    };

    const idPrefix = `proposal-${proposal.id}`;

    return (
        <li className={cn(sectionClass, 'gap-3')}>
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h3 className="font-display text-lg font-bold">
                    {proposal.track}
                </h3>
                <span
                    className={cn(
                        'rounded-sm border px-2 py-0.5 text-[13px] font-semibold',
                        proposal.hidden
                            ? 'border-err text-err'
                            : 'border-border-strong text-muted',
                    )}
                >
                    {proposal.hidden
                        ? t('admin.proposals.stateHidden')
                        : t('admin.proposals.stateVisible')}
                </span>
            </div>
            <p className="text-sm">{proposal.cars.join(', ')}</p>
            <p className="text-sm text-muted">
                {proposal.author === null
                    ? t('admin.proposals.noAuthor')
                    : t('admin.proposals.author', {
                          name: proposal.author.name,
                          id: proposal.author.id,
                      })}
                {' · '}
                <span className="mono">
                    {t('admin.proposals.votes', { n: proposal.votes })}
                </span>
            </p>
            {proposal.hidden ? (
                <div className="grid gap-3">
                    <p className="text-sm">
                        <span className="font-semibold">
                            {t('admin.proposals.reason')}:
                        </span>{' '}
                        {proposal.hidden_reason}
                    </p>
                    <div>
                        <button
                            type="button"
                            onClick={submitShow}
                            disabled={form.processing}
                            className={cn(secondaryButtonClass, 'min-h-11')}
                        >
                            {t('admin.proposals.show')}
                        </button>
                    </div>
                </div>
            ) : (
                <form onSubmit={submitHide} noValidate className="grid gap-2">
                    <label
                        htmlFor={`${idPrefix}-reason`}
                        className="text-sm font-semibold"
                    >
                        {t('admin.proposals.reasonLabel')}
                    </label>
                    <div className="flex flex-wrap gap-3">
                        <input
                            id={`${idPrefix}-reason`}
                            type="text"
                            autoComplete="off"
                            maxLength={500}
                            value={form.data.reason}
                            aria-invalid={Boolean(reasonError)}
                            aria-describedby={
                                reasonError ? `${idPrefix}-error` : undefined
                            }
                            onChange={(event) => {
                                form.clearErrors();
                                form.setData('reason', event.target.value);
                            }}
                            className={cn(inputClass, 'min-w-0 flex-1')}
                        />
                        <button
                            type="submit"
                            disabled={form.processing}
                            className={cn(dangerButtonClass, 'min-h-11')}
                        >
                            {t('admin.proposals.hide')}
                        </button>
                    </div>
                </form>
            )}
            {reasonError || proposalError ? (
                <p
                    id={`${idPrefix}-error`}
                    role="alert"
                    className={fieldErrorClass}
                >
                    {reasonError ?? proposalError}
                </p>
            ) : null}
        </li>
    );
}

export default function AdminProposals({
    week,
    proposals,
}: {
    week: { id: number; starts_at: string; ends_at: string } | null;
    proposals: AdminProposal[];
}) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const dateOptions: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
    };

    return (
        <>
            <Head title={t('admin.proposals.title')} />
            <div className="grid gap-7">
                <div>
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('admin.proposals.title')}
                    </h1>
                    <p className="mt-2 max-w-[60ch] text-muted">
                        {t('admin.proposals.sub')}
                    </p>
                    {week === null ? null : (
                        <p className="mt-1 text-sm text-muted">
                            {t('admin.proposals.week', {
                                range: `${formatDate(new Date(week.starts_at), locale, dateOptions)} – ${formatDate(new Date(week.ends_at), locale, dateOptions)}`,
                            })}
                        </p>
                    )}
                </div>
                {week === null ? (
                    <p role="status" className="text-muted">
                        {t('admin.proposals.noWeek')}
                    </p>
                ) : proposals.length === 0 ? (
                    <p role="status" className="text-muted">
                        {t('admin.proposals.empty')}
                    </p>
                ) : (
                    <ul className="grid max-w-3xl gap-4">
                        {proposals.map((proposal) => (
                            <ProposalRow
                                key={proposal.id}
                                proposal={proposal}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
