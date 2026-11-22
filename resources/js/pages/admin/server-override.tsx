import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Zap } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { warningClass } from '@/components/account-styles';
import { CarsTab } from '@/components/admin/server-override/cars-tab';
import type {
    ActiveOverride,
    OverrideTabContext,
    PageOptions,
} from '@/components/admin/server-override/context';
import { DurationTab } from '@/components/admin/server-override/duration-tab';
import { EventTab } from '@/components/admin/server-override/event-tab';
import { ServerTab } from '@/components/admin/server-override/server-tab';
import { SessionsTab } from '@/components/admin/server-override/sessions-tab';
import { Button } from '@/components/ui/button';
import { Tabs } from '@/components/ui/tabs';
import type { TabItem } from '@/components/ui/tabs';
import { useT } from '@/hooks/use-t';
import { formatDateTime } from '@/lib/datetime';
import { hasRaceWithoutQualifying } from '@/lib/schedule-form';
import {
    clampMaxPlayers,
    countErrorsByTab,
    firstTabWithErrors,
    formFromConfig,
    isPitstopEligible,
    isPracticeUnavailable,
    maxPlayersFor,
    overrideTabIds,
    toPayload,
    validateOverrideForm,
} from '@/lib/server-override-form';
import type {
    OverrideConfig,
    OverrideError,
    OverrideForm,
    OverrideTabId,
    RaceForm,
    SessionForm,
    SessionKey,
} from '@/lib/server-override-form';
import { buildTabUrl, readTabFromUrl, resolveTabId } from '@/lib/tab-query';
import { destroy, store } from '@/routes/admin/override';

type PageProps = {
    server: {
        id: number;
        name: string;
        max_players: number;
        planning_paused: boolean;
    };
    config: OverrideConfig | null;
    configError: string | null;
    override: ActiveOverride | null;
    players: number | null;
    options: PageOptions;
};

const tabQueryKey = 'tab';

export default function AdminServerOverride({
    server,
    config,
    configError,
    override,
    players,
    options,
}: PageProps) {
    const t = useT();
    const page = usePage<{ locale: string }>();
    const { locale } = page.props;
    const form = useForm<OverrideForm>(formFromConfig(config));
    const [attempted, setAttempted] = useState(false);
    const [busy, setBusy] = useState(false);
    const [activeTab, setActiveTab] = useState<OverrideTabId>(
        () =>
            resolveTabId(
                overrideTabIds,
                readTabFromUrl(page.url, tabQueryKey),
            ) as OverrideTabId,
    );
    const activeTabRef = useRef(activeTab);
    activeTabRef.current = activeTab;
    const data = form.data;
    const raceWeekend = data.mode === 'race_weekend';
    const track = options.tracks.find((item) => item.id === data.track_id);
    const maxPlayers = maxPlayersFor(data.mode, track);
    const practiceMissing = isPracticeUnavailable(data.mode, track);
    const pitstopEligible = isPitstopEligible(
        data.mode,
        data.race.duration_type,
        data.race.length_min,
    );
    const clientErrors = validateOverrideForm(data, track, new Date());
    const serverErrors: Record<string, string | undefined> = form.errors;

    const message = (error: OverrideError | undefined): string | undefined =>
        error ? t(error.key, error.params) : undefined;

    const errors: Record<string, string | undefined> = { ...serverErrors };

    if (attempted) {
        Object.entries(clientErrors).forEach(([key, error]) => {
            errors[key] ??= message(error);
        });
    }

    const label = (prefix: string, value: string | null): string => {
        if (value === null) {
            return '-';
        }

        const translated = t(`${prefix}${value}`);

        return translated === `${prefix}${value}` ? value : translated;
    };

    const showTab = (id: OverrideTabId): void => {
        setActiveTab(id);
        window.history.replaceState(
            window.history.state,
            '',
            buildTabUrl(
                window.location.pathname +
                    window.location.search +
                    window.location.hash,
                tabQueryKey,
                id,
            ),
        );
    };

    const set = (patch: Partial<OverrideForm>): void => {
        const next = { ...data, ...patch };
        const nextTrack = options.tracks.find(
            (item) => item.id === next.track_id,
        );

        next.max_players = clampMaxPlayers(
            next.max_players,
            maxPlayersFor(next.mode, nextTrack),
        );
        form.clearErrors();
        form.setData(next);
    };

    const setSession = (
        key: Exclude<SessionKey, 'race'>,
        patch: Partial<SessionForm>,
    ): void => set({ [key]: { ...data[key], ...patch } });

    const setRace = (patch: Partial<RaceForm>): void =>
        set({ race: { ...data.race, ...patch } });

    const apply = (): void => {
        setAttempted(true);

        if (practiceMissing || Object.keys(clientErrors).length > 0) {
            const firstTab = firstTabWithErrors(Object.keys(clientErrors));

            if (firstTab !== null) {
                showTab(firstTab);
            }

            return;
        }

        const confirmation =
            players === null
                ? t('admin.override.confirmApply')
                : t('admin.override.confirmApplyPlayers', { players });

        if (!window.confirm(confirmation)) {
            return;
        }

        form.transform((current) => toPayload(current));
        form.post(store().url, {
            preserveScroll: true,
            onError: (serverFormErrors) => {
                const firstTab = firstTabWithErrors(
                    Object.keys(serverFormErrors),
                );

                if (firstTab !== null) {
                    showTab(firstTab);
                }
            },
            onSuccess: () => showTab(activeTabRef.current),
        });
    };

    const cut = (): void => {
        const confirmation =
            players === null
                ? t('admin.override.confirmCut')
                : t('admin.override.confirmCutPlayers', { players });

        if (!window.confirm(confirmation)) {
            return;
        }

        router.delete(destroy().url, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    };

    const dateTime = (value: string): string =>
        formatDateTime(new Date(value), locale, {
            day: 'numeric',
            month: 'short',
        });

    const disabled = form.processing || busy;
    const issues = Object.entries(clientErrors).map(([key, error]) => ({
        key,
        text: message(error) as string,
    }));

    const errorCounts = countErrorsByTab(
        Object.keys(errors).filter((key) => errors[key]),
    );
    const warnings: Record<OverrideTabId, boolean> = {
        server: false,
        event: practiceMissing,
        cars: false,
        sessions:
            raceWeekend &&
            hasRaceWithoutQualifying(true, true, data.qualify.length_min),
        duration: false,
    };

    const ctx: OverrideTabContext = {
        data,
        errors,
        options,
        maxPlayers,
        raceWeekend,
        practiceMissing,
        pitstopEligible,
        label,
        set,
        setSession,
        setRace,
    };

    const tab = (
        id: OverrideTabId,
        tabLabel: string,
        panel: ReactNode,
        status?: ReactNode,
    ): TabItem => ({
        id,
        label: tabLabel,
        status,
        badge: errorCounts[id],
        dot: warnings[id],
        indicatorLabel:
            errorCounts[id] > 0
                ? t('admin.override.tabErrors')
                : t('admin.override.tabWarning'),
        panel,
    });

    const items: TabItem[] = [
        tab(
            'server',
            t('admin.override.sectionServer'),
            <ServerTab ctx={ctx} />,
        ),
        tab('event', t('admin.override.sectionEvent'), <EventTab ctx={ctx} />),
        tab(
            'cars',
            t('admin.override.cars'),
            <CarsTab ctx={ctx} />,
            data.car_ids.length,
        ),
        tab(
            'sessions',
            t('admin.override.sectionSessions'),
            <SessionsTab ctx={ctx} />,
        ),
        tab(
            'duration',
            t('admin.override.tabDuration'),
            <DurationTab
                ctx={ctx}
                override={override}
                players={players}
                issues={issues}
                dateTime={dateTime}
            />,
            data.until_enabled && data.until
                ? data.until.replace('T', ' ')
                : t('admin.override.indefinite'),
        ),
    ];

    return (
        <>
            <Head title={t('admin.override.title')} />
            <form
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    apply();
                }}
                className="grid gap-5"
            >
                <div className="sticky top-16 z-20 -mx-4 grid gap-3 border-b border-border bg-bg px-4 py-3 md:-mx-8 md:px-8">
                    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
                        <div className="min-w-0">
                            <h1 className="font-display text-2xl leading-tight font-bold tracking-[-0.02em] md:text-3xl">
                                {t('admin.override.title')}
                            </h1>
                            <p className="truncate text-sm text-muted">
                                {server.name}
                            </p>
                        </div>
                        <Button
                            type="submit"
                            disabled={disabled || practiceMissing}
                        >
                            <Zap aria-hidden="true" />
                            {t('admin.override.apply')}
                        </Button>
                    </div>
                    {override ? (
                        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-md border border-primary px-3.5 py-2 text-sm">
                            <p>
                                <span className="font-semibold">
                                    {t('admin.override.activeTitle')}
                                </span>
                                <span className="text-muted">
                                    {' · '}
                                    {t('admin.override.since')}{' '}
                                    {dateTime(override.created_at)}
                                    {' · '}
                                    {t('admin.override.until')}{' '}
                                    {override.until
                                        ? dateTime(override.until)
                                        : t('admin.override.indefinite')}
                                </span>
                            </p>
                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                disabled={disabled}
                                onClick={cut}
                            >
                                {t('admin.override.cut')}
                            </Button>
                        </div>
                    ) : null}
                    {server.planning_paused ? (
                        <p role="status" className={warningClass}>
                            {t('admin.override.planningPaused')}
                        </p>
                    ) : null}
                    {configError ? (
                        <p role="alert" className={warningClass}>
                            {configError} {t('admin.override.defaultsUsed')}
                        </p>
                    ) : null}
                </div>
                <Tabs
                    label={t('admin.override.tabsLabel')}
                    items={items}
                    activeId={activeTab}
                    onChange={(id) => setActiveTab(id as OverrideTabId)}
                    queryKey={tabQueryKey}
                />
            </form>
        </>
    );
}
