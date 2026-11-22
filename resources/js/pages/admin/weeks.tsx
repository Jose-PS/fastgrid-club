import { Head, useForm, usePage } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    fieldErrorClass,
    inputClass,
    primaryButtonClass,
    secondaryButtonClass,
    sectionClass,
    warningClass,
} from '@/components/account-styles';
import { CarCombobox } from '@/components/proposals/car-combobox';
import { TrackCombobox } from '@/components/proposals/track-combobox';
import { Tabs } from '@/components/ui/tabs';
import { useT } from '@/hooks/use-t';
import { formatDate } from '@/lib/datetime';
import { hasValidCarCount } from '@/lib/proposal-form';
import { showToast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { update, winner } from '@/routes/admin/weeks';
import type { ProposalOptions } from '@/types/proposals';

type Candidate = {
    id: number;
    track: string;
    cars: string[];
    votes: number;
};

type AdminWeek = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    config_source: string | null;
    custom_title: string | null;
    track_id: number | null;
    track: string | null;
    car_ids: number[];
    cars: string[];
    is_current: boolean;
    editable: boolean;
    proposals: Candidate[] | null;
};

type LiveServer = { players: number | null; paused: boolean };

function activeConfirm(
    t: (key: string, params?: Record<string, string | number>) => string,
    live: LiveServer | null,
): string {
    if (live === null || live.paused) {
        return t('admin.weeks.confirmActive');
    }

    return t('admin.weeks.confirmActivePlayers', {
        players:
            live.players === null
                ? t('admin.servers.playersUnknown')
                : live.players,
    });
}

function ActiveWarning({ live }: { live: LiveServer | null }) {
    const t = useT();

    if (live?.paused) {
        return (
            <div role="alert" className={warningClass}>
                <p className="text-sm font-semibold">
                    {t('admin.weeks.activePausedWarning')}
                </p>
            </div>
        );
    }

    return (
        <div role="alert" className={warningClass}>
            <div className="grid gap-0.5">
                <p className="text-sm font-semibold">
                    {t('admin.weeks.activeRestartWarning')}
                </p>
                <p className="text-sm font-normal">
                    {live === null || live.players === null
                        ? t('admin.weeks.activePlayersUnknown')
                        : t('admin.weeks.activePlayers', { n: live.players })}
                </p>
            </div>
        </div>
    );
}

type ForceForm = {
    track_id: number | '';
    cars: number[];
    custom_title: string;
};

function ForceWeekForm({
    week,
    options,
    live,
    onDone,
}: {
    week: AdminWeek;
    options: ProposalOptions;
    live: LiveServer | null;
    onDone: () => void;
}) {
    const t = useT();
    const form = useForm<ForceForm>({
        track_id: week.track_id ?? '',
        cars: week.car_ids,
        custom_title: week.custom_title ?? '',
    });
    const errors: Record<string, string | undefined> = form.errors;
    const carsError = errors.cars ?? errors['cars.0'];
    const prefix = `week-${week.id}`;

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (form.data.track_id === '') {
            form.setError('track_id', t('props.errTrack'));

            return;
        }

        if (!hasValidCarCount(form.data.cars)) {
            form.setError('cars', t('props.errCars'));

            return;
        }

        if (form.data.custom_title.length > 255) {
            form.setError('custom_title', t('admin.defaultConfig.errTitle'));

            return;
        }

        const message = week.is_current
            ? activeConfirm(t, live)
            : t('admin.weeks.confirmForce');

        if (!window.confirm(message)) {
            return;
        }

        form.clearErrors();
        form.put(update(week.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                showToast({
                    type: 'success',
                    message: t('admin.weeks.forced'),
                });
                onDone();
            },
        });
    };

    return (
        <form
            onSubmit={submit}
            noValidate
            aria-label={t('admin.weeks.force')}
            className="grid gap-4"
        >
            {week.is_current ? <ActiveWarning live={live} /> : null}
            <div className="grid gap-1.5">
                <label
                    htmlFor={`${prefix}-track`}
                    className="text-sm font-semibold"
                >
                    {t('props.circuit')}
                </label>
                <TrackCombobox
                    id={`${prefix}-track`}
                    tracks={options.tracks}
                    value={form.data.track_id}
                    invalid={Boolean(errors.track_id)}
                    onChange={(trackId) => {
                        form.clearErrors('track_id');
                        form.setData('track_id', trackId);
                    }}
                />
                {errors.track_id ? (
                    <p role="alert" className={fieldErrorClass}>
                        {errors.track_id}
                    </p>
                ) : null}
            </div>
            <div className="grid gap-1.5">
                <div className="flex flex-wrap items-baseline gap-x-3">
                    <label
                        id={`${prefix}-cars-label`}
                        htmlFor={`${prefix}-cars`}
                        className="text-sm font-semibold"
                    >
                        {t('props.cars')}
                    </label>
                    <span className="mono text-xs text-muted">
                        {form.data.cars.length} · {t('props.carsHint')}
                    </span>
                </div>
                <CarCombobox
                    id={`${prefix}-cars`}
                    labelId={`${prefix}-cars-label`}
                    cars={options.cars}
                    selectedIds={form.data.cars}
                    invalid={Boolean(carsError)}
                    onChange={(carIds) => {
                        form.clearErrors('cars');
                        form.setData('cars', carIds);
                    }}
                />
                {carsError ? (
                    <p
                        role="alert"
                        className={cn(
                            fieldErrorClass,
                            'flex items-center gap-1.5',
                        )}
                    >
                        <CircleAlert
                            className="size-4 flex-none"
                            aria-hidden="true"
                        />
                        {carsError}
                    </p>
                ) : null}
            </div>
            <div className="grid gap-1.5">
                <label
                    htmlFor={`${prefix}-title`}
                    className="text-sm font-semibold"
                >
                    {t('admin.defaultConfig.titleLabel')}
                </label>
                <input
                    id={`${prefix}-title`}
                    type="text"
                    autoComplete="off"
                    value={form.data.custom_title}
                    aria-invalid={Boolean(errors.custom_title)}
                    onChange={(event) => {
                        form.clearErrors('custom_title');
                        form.setData('custom_title', event.target.value);
                    }}
                    className={inputClass}
                />
                {errors.custom_title ? (
                    <p role="alert" className={fieldErrorClass}>
                        {errors.custom_title}
                    </p>
                ) : null}
            </div>
            {errors.week ? (
                <p role="alert" className={fieldErrorClass}>
                    {errors.week}
                </p>
            ) : null}
            <div className="flex flex-wrap gap-3">
                <button
                    type="submit"
                    disabled={form.processing}
                    className={cn(primaryButtonClass, 'min-h-12')}
                >
                    {t('admin.weeks.save')}
                </button>
                <button
                    type="button"
                    onClick={onDone}
                    className={cn(secondaryButtonClass, 'min-h-12')}
                >
                    {t('admin.weeks.cancel')}
                </button>
            </div>
        </form>
    );
}

function WinnerForm({
    week,
    candidates,
    live,
}: {
    week: AdminWeek;
    candidates: Candidate[];
    live: LiveServer | null;
}) {
    const t = useT();
    const form = useForm<{ proposal_id: number | '' }>({ proposal_id: '' });
    const errors: Record<string, string | undefined> = form.errors;
    const selectId = `week-${week.id}-winner`;

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (form.data.proposal_id === '') {
            form.setError('proposal_id', t('admin.weeks.errWinner'));

            return;
        }

        const message = week.is_current
            ? activeConfirm(t, live)
            : t('admin.weeks.confirmWinner');

        if (!window.confirm(message)) {
            return;
        }

        form.clearErrors();
        form.post(winner(week.id).url, {
            preserveScroll: true,
            onSuccess: () =>
                showToast({
                    type: 'success',
                    message: t('admin.weeks.winnerChosen'),
                }),
        });
    };

    return (
        <form onSubmit={submit} noValidate className="grid gap-2">
            <label htmlFor={selectId} className="text-sm font-semibold">
                {t('admin.weeks.winnerLabel')}
            </label>
            {candidates.length === 0 ? (
                <p className="text-sm text-muted">
                    {t('admin.weeks.noCandidates')}
                </p>
            ) : (
                <div className="flex flex-wrap gap-3">
                    <select
                        id={selectId}
                        value={form.data.proposal_id}
                        aria-invalid={Boolean(errors.proposal_id)}
                        onChange={(event) => {
                            form.clearErrors();
                            form.setData(
                                'proposal_id',
                                event.target.value === ''
                                    ? ''
                                    : Number(event.target.value),
                            );
                        }}
                        className={cn(inputClass, 'min-w-0 flex-1')}
                    >
                        <option value="">
                            {t('admin.weeks.winnerPlaceholder')}
                        </option>
                        {candidates.map((candidate) => (
                            <option key={candidate.id} value={candidate.id}>
                                {`${candidate.track} · ${candidate.cars.join(', ')} · ${t('admin.proposals.votes', { n: candidate.votes })}`}
                            </option>
                        ))}
                    </select>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className={cn(primaryButtonClass, 'min-h-11')}
                    >
                        {t('admin.weeks.chooseWinner')}
                    </button>
                </div>
            )}
            {errors.proposal_id || errors.week ? (
                <p role="alert" className={fieldErrorClass}>
                    {errors.proposal_id ?? errors.week}
                </p>
            ) : null}
        </form>
    );
}

function WeekItem({
    week,
    options,
    live,
    locale,
}: {
    week: AdminWeek;
    options: ProposalOptions;
    live: LiveServer | null;
    locale: string;
}) {
    const t = useT();
    const [editing, setEditing] = useState(false);
    const dateOptions: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
    };

    return (
        <li className={cn(sectionClass, 'gap-3')}>
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h3 className="font-display text-lg font-bold">
                    {formatDate(new Date(week.starts_at), locale, dateOptions)}
                    {' – '}
                    {formatDate(new Date(week.ends_at), locale, dateOptions)}
                </h3>
                <span className="text-sm text-muted">
                    {week.is_current ? `${t('admin.weeks.current')} · ` : ''}
                    {t(`admin.panel.status.${week.status}`)}
                </span>
            </div>
            <dl className="grid gap-2 text-sm">
                <div>
                    <dt className="text-muted">{t('admin.panel.circuit')}</dt>
                    <dd className="font-semibold">
                        {week.track ?? t('admin.panel.noConfig')}
                    </dd>
                </div>
                {week.cars.length === 0 ? null : (
                    <div>
                        <dt className="text-muted">{t('admin.panel.cars')}</dt>
                        <dd>{week.cars.join(', ')}</dd>
                    </div>
                )}
                {week.config_source === null ? null : (
                    <div>
                        <dt className="text-muted">
                            {t('admin.panel.source')}
                        </dt>
                        <dd>{t(`admin.panel.source.${week.config_source}`)}</dd>
                    </div>
                )}
                {week.custom_title === null ? null : (
                    <div>
                        <dt className="text-muted">
                            {t('admin.panel.customTitle')}
                        </dt>
                        <dd>{week.custom_title}</dd>
                    </div>
                )}
            </dl>
            {week.editable ? (
                editing ? (
                    <ForceWeekForm
                        week={week}
                        options={options}
                        live={live}
                        onDone={() => setEditing(false)}
                    />
                ) : (
                    <div>
                        <button
                            type="button"
                            onClick={() => setEditing(true)}
                            className={cn(secondaryButtonClass, 'min-h-11')}
                        >
                            {t('admin.weeks.force')}
                        </button>
                    </div>
                )
            ) : null}
            {week.proposals === null ? null : (
                <WinnerForm
                    week={week}
                    candidates={week.proposals}
                    live={live}
                />
            )}
        </li>
    );
}

export default function AdminWeeks({
    weeks,
    options,
    live,
}: {
    weeks: AdminWeek[];
    options: ProposalOptions;
    live: LiveServer | null;
}) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const activeWeeks = weeks.filter((week) => week.editable);
    const pastWeeks = weeks.filter((week) => !week.editable);
    const renderWeeks = (list: AdminWeek[], emptyMessage: string) =>
        list.length === 0 ? (
            <p role="status" className="text-muted">
                {emptyMessage}
            </p>
        ) : (
            <ul className="grid max-w-3xl gap-4">
                {list.map((week) => (
                    <WeekItem
                        key={week.id}
                        week={week}
                        options={options}
                        live={live}
                        locale={locale}
                    />
                ))}
            </ul>
        );

    return (
        <>
            <Head title={t('admin.weeks.title')} />
            <div className="grid gap-7">
                <div>
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('admin.weeks.title')}
                    </h1>
                    <p className="mt-2 max-w-[60ch] text-muted">
                        {t('admin.weeks.sub')}
                    </p>
                </div>
                {weeks.length === 0 ? (
                    <p role="status" className="text-muted">
                        {t('admin.weeks.empty')}
                    </p>
                ) : (
                    <Tabs
                        label={t('admin.weeks.tabsLabel')}
                        queryKey="tab"
                        defaultId={activeWeeks.length > 0 ? 'active' : 'past'}
                        items={[
                            {
                                id: 'active',
                                label: t('admin.weeks.tabActive'),
                                panel: renderWeeks(
                                    activeWeeks,
                                    t('admin.weeks.emptyActive'),
                                ),
                            },
                            {
                                id: 'past',
                                label: t('admin.weeks.tabPast'),
                                panel: renderWeeks(
                                    pastWeeks,
                                    t('admin.weeks.emptyPast'),
                                ),
                            },
                        ]}
                    />
                )}
            </div>
        </>
    );
}
