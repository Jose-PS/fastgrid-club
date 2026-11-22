import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Coins,
    KeyRound,
    ScrollText,
    Server,
    Settings2,
} from 'lucide-react';
import type { ServerSummary } from '@/components/admin/server-types';
import {
    secondaryButtonClass,
    sectionClass,
    sectionTitleClass,
    warningClass,
} from '@/components/account-styles';
import { useT } from '@/hooks/use-t';
import { Badge } from '@/components/ui/badge';
import { Tabs } from '@/components/ui/tabs';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { showToast } from '@/lib/toast';
import { show as account } from '@/routes/account';
import { close as closeAlert } from '@/routes/admin/alerts';
import { index as audit } from '@/routes/admin/audit';
import { index as catalog } from '@/routes/admin/catalog';
import { show as defaultConfig } from '@/routes/admin/default-config';
import { show as donations } from '@/routes/admin/donations';
import { index as serversIndex } from '@/routes/admin/servers';
import { index as weeksIndex } from '@/routes/admin/weeks';

type AdminWeek = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    config_source: string | null;
    custom_title: string | null;
    track: string | null;
    cars: string[];
};

type AdminAlert = {
    id: number;
    type: string;
    summary: string;
    opened_at: string;
    sources: string[];
};

type AdminServer = ServerSummary & { name: string };

type AdminHeartbeat = {
    source: string;
    last_beat_at: string | null;
    age_seconds: number | null;
    healthy: boolean;
    offsite: boolean | null;
};

const cardClass =
    'grid gap-1.5 rounded-md border border-border bg-surface p-4.5 transition-colors duration-150 ease-out hover:bg-surface-2';

function heartbeatAge(
    t: ReturnType<typeof useT>,
    ageSeconds: number | null,
): string {
    if (ageSeconds === null) {
        return t('admin.heartbeats.never');
    }

    if (ageSeconds < 3600) {
        return t('admin.heartbeats.ageMinutes', {
            minutes: Math.floor(ageSeconds / 60),
        });
    }

    if (ageSeconds < 172800) {
        return t('admin.heartbeats.ageHours', {
            hours: Math.floor(ageSeconds / 3600),
        });
    }

    return t('admin.heartbeats.ageDays', {
        days: Math.floor(ageSeconds / 86400),
    });
}

function WeekCard({
    heading,
    week,
    locale,
}: {
    heading: string;
    week: AdminWeek | null;
    locale: string;
}) {
    const t = useT();
    const dateOptions: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
    };

    return (
        <section className={sectionClass}>
            <h3 className={sectionTitleClass}>{heading}</h3>
            {week === null ? (
                <p className="text-muted">{t('admin.panel.noWeek')}</p>
            ) : (
                <dl className="grid gap-2 text-sm">
                    <div className="flex flex-wrap items-baseline gap-x-2">
                        <dt className="sr-only">{heading}</dt>
                        <dd className="font-semibold">
                            {formatDate(
                                new Date(week.starts_at),
                                locale,
                                dateOptions,
                            )}
                            {' – '}
                            {formatDate(
                                new Date(week.ends_at),
                                locale,
                                dateOptions,
                            )}
                            <span className="ml-2 font-normal text-muted">
                                {t(`admin.panel.status.${week.status}`)}
                            </span>
                        </dd>
                    </div>
                    {week.track === null ? (
                        <div>
                            <dd className="text-muted">
                                {t('admin.panel.noConfig')}
                            </dd>
                        </div>
                    ) : (
                        <>
                            <div>
                                <dt className="text-muted">
                                    {t('admin.panel.circuit')}
                                </dt>
                                <dd className="font-semibold">{week.track}</dd>
                            </div>
                            <div>
                                <dt className="text-muted">
                                    {t('admin.panel.cars')}
                                </dt>
                                <dd>{week.cars.join(', ')}</dd>
                            </div>
                        </>
                    )}
                    {week.config_source === null ? null : (
                        <div>
                            <dt className="text-muted">
                                {t('admin.panel.source')}
                            </dt>
                            <dd>
                                {t(`admin.panel.source.${week.config_source}`)}
                            </dd>
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
            )}
        </section>
    );
}

export default function AdminIndex({
    server,
    weeks,
    missingPasskey,
    alerts,
    heartbeats,
}: {
    server: AdminServer | null;
    weeks: { current: AdminWeek | null; next: AdminWeek | null };
    missingPasskey: boolean;
    alerts: AdminAlert[];
    heartbeats: AdminHeartbeat[];
}) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const dateTime = (value: string | null): string =>
        value === null
            ? t('admin.panel.never')
            : formatDateTime(new Date(value), locale, {
                  day: 'numeric',
                  month: 'short',
              });
    const sections = [
        {
            key: 'default-config',
            href: defaultConfig().url,
            icon: Settings2,
            title: t('admin.defaultConfig.title'),
            sub: t('admin.defaultConfig.sub'),
        },
        {
            key: 'servers',
            href: serversIndex().url,
            icon: Server,
            title: t('admin.servers.title'),
            sub: t('admin.servers.sub'),
        },
        {
            key: 'catalog',
            href: catalog().url,
            icon: BookOpen,
            title: t('admin.catalog.title'),
            sub: t('admin.catalog.sub'),
        },
        {
            key: 'donations',
            href: donations().url,
            icon: Coins,
            title: t('admin.donations.title'),
            sub: t('admin.donations.sub'),
        },
        {
            key: 'audit',
            href: audit().url,
            icon: ScrollText,
            title: t('admin.audit.title'),
            sub: t('admin.audit.sub'),
        },
    ];

    const staleHeartbeats = heartbeats.filter(
        (heartbeat) => !heartbeat.healthy,
    ).length;

    const close = (alert: AdminAlert): void => {
        if (!window.confirm(t('admin.alerts.confirmClose'))) {
            return;
        }

        router.post(
            closeAlert(alert.id).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    showToast({
                        type: 'success',
                        message: t('admin.alerts.closed'),
                    }),
            },
        );
    };

    return (
        <>
            <Head title={t('admin.title')} />
            <div className="grid gap-7">
                <div>
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('admin.title')}
                    </h1>
                    <p className="mt-2 text-muted">{t('admin.sub')}</p>
                </div>
                {missingPasskey ? (
                    <div role="status" className={warningClass}>
                        <div className="grid gap-0.5">
                            <p className="inline-flex items-center gap-2 font-semibold">
                                <KeyRound
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {t('admin.panel.passkeyTitle')}
                            </p>
                            <p className="text-sm font-normal">
                                {t('admin.panel.passkeyBody')}
                            </p>
                        </div>
                        <Link
                            href={account().url}
                            className="font-semibold underline underline-offset-2"
                        >
                            {t('admin.panel.passkeyLink')}
                        </Link>
                    </div>
                ) : null}
                <section
                    aria-labelledby="admin-server-title"
                    className={sectionClass}
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2
                            id="admin-server-title"
                            className={sectionTitleClass}
                        >
                            {t('admin.panel.serverTitle')}
                            {server === null ? '' : ` · ${server.name}`}
                        </h2>
                        {server === null ? null : (
                            <div className="flex flex-wrap items-center gap-2">
                                {server.planning_paused ? (
                                    <Badge variant="outline">
                                        {t('admin.panel.pausedBadge')}
                                    </Badge>
                                ) : null}
                                <Badge
                                    variant={
                                        server.live.status === 'online'
                                            ? 'default'
                                            : 'destructive'
                                    }
                                >
                                    {t(
                                        `admin.servers.status.${server.live.status}`,
                                    )}
                                </Badge>
                            </div>
                        )}
                    </div>
                    {server === null ? (
                        <p role="status" className="text-muted">
                            {t('admin.panel.noServer')}
                        </p>
                    ) : (
                        <>
                            {server.pending_config ? (
                                <div role="status" className={warningClass}>
                                    <div className="grid gap-0.5">
                                        <p className="font-semibold">
                                            {t('admin.panel.pendingTitle')}
                                        </p>
                                        <p className="text-sm font-normal">
                                            {t('admin.panel.pendingBody')}
                                        </p>
                                    </div>
                                    <Link
                                        href={serversIndex().url}
                                        className="font-semibold underline underline-offset-2"
                                    >
                                        {t('admin.panel.viewServers')}
                                    </Link>
                                </div>
                            ) : null}
                            <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <dt className="text-muted">
                                        {t('admin.panel.serverPlayers')}
                                    </dt>
                                    <dd className="font-semibold">
                                        {server.live.players === null
                                            ? t('admin.servers.playersUnknown')
                                            : server.live.players}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted">
                                        {t('admin.panel.serverBlock')}
                                    </dt>
                                    <dd className="font-semibold">
                                        {t(
                                            `admin.servers.block.${server.block}`,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted">
                                        {t('admin.panel.lastSync')}
                                    </dt>
                                    <dd className="font-semibold">
                                        {dateTime(
                                            server.last_catalog_sync
                                                ?.finished_at ?? null,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted">
                                        {t('admin.panel.lastApply')}
                                    </dt>
                                    <dd className="font-semibold">
                                        {server.last_apply === null
                                            ? t('admin.panel.never')
                                            : `${t(`admin.servers.appStatus.${server.last_apply.status}`)} · ${dateTime(server.last_apply.finished_at ?? server.last_apply.started_at)}`}
                                    </dd>
                                </div>
                            </dl>
                            <div>
                                <Link
                                    href={serversIndex().url}
                                    className="text-sm font-semibold underline underline-offset-2"
                                >
                                    {t('admin.panel.viewServers')}
                                </Link>
                            </div>
                        </>
                    )}
                </section>
                <Tabs
                    label={t('admin.panel.tabsLabel')}
                    queryKey="tab"
                    defaultId="alerts"
                    items={[
                        {
                            id: 'alerts',
                            label: t('admin.panel.tabAlerts'),
                            badge: alerts.length,
                            indicatorLabel: t('admin.panel.alertsIndicator'),
                            panel: (
                                <section
                                    aria-labelledby="admin-alerts-title"
                                    className="grid gap-4"
                                >
                                    <h2
                                        id="admin-alerts-title"
                                        className={sectionTitleClass}
                                    >
                                        {t('admin.alerts.title')}
                                    </h2>
                                    {alerts.length === 0 ? (
                                        <p role="status" className="text-muted">
                                            {t('admin.alerts.empty')}
                                        </p>
                                    ) : (
                                        <ul className="grid gap-3">
                                            {alerts.map((alert) => (
                                                <li
                                                    key={alert.id}
                                                    className={warningClass}
                                                >
                                                    <div className="grid gap-0.5">
                                                        <p className="font-semibold">
                                                            {alert.summary}
                                                        </p>
                                                        {alert.sources
                                                            .length ===
                                                        0 ? null : (
                                                            <p className="text-sm font-normal">
                                                                {t(
                                                                    'admin.alerts.sourceLabel',
                                                                    {
                                                                        sources:
                                                                            alert.sources
                                                                                .map(
                                                                                    (
                                                                                        source,
                                                                                    ) =>
                                                                                        t(
                                                                                            `admin.alerts.source.${source}`,
                                                                                        ),
                                                                                )
                                                                                .join(
                                                                                    ', ',
                                                                                ),
                                                                    },
                                                                )}
                                                            </p>
                                                        )}
                                                        <p className="text-sm font-normal">
                                                            {t(
                                                                'admin.alerts.opened',
                                                                {
                                                                    date: formatDate(
                                                                        new Date(
                                                                            alert.opened_at,
                                                                        ),
                                                                        locale,
                                                                        {
                                                                            day: 'numeric',
                                                                            month: 'short',
                                                                        },
                                                                    ),
                                                                },
                                                            )}
                                                        </p>
                                                    </div>
                                                    <div className="flex flex-wrap items-center gap-3">
                                                        {alert.type ===
                                                            'week_fallback_pending_review' && (
                                                            <Link
                                                                href={
                                                                    weeksIndex()
                                                                        .url
                                                                }
                                                                className="font-semibold underline underline-offset-2"
                                                            >
                                                                {t(
                                                                    'admin.alerts.viewWeeks',
                                                                )}
                                                            </Link>
                                                        )}
                                                        {(alert.type ===
                                                            'integration_failing' ||
                                                            alert.type ===
                                                                'log_gap') && (
                                                            <Link
                                                                href={
                                                                    serversIndex()
                                                                        .url
                                                                }
                                                                className="font-semibold underline underline-offset-2"
                                                            >
                                                                {t(
                                                                    'admin.alerts.viewServers',
                                                                )}
                                                            </Link>
                                                        )}
                                                        {alert.type ===
                                                            'catalog_stale' && (
                                                            <Link
                                                                href={
                                                                    catalog()
                                                                        .url
                                                                }
                                                                className="font-semibold underline underline-offset-2"
                                                            >
                                                                {t(
                                                                    'admin.alerts.viewCatalog',
                                                                )}
                                                            </Link>
                                                        )}
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                close(alert)
                                                            }
                                                            className={
                                                                secondaryButtonClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.alerts.close',
                                                            )}
                                                        </button>
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </section>
                            ),
                        },
                        {
                            id: 'weeks',
                            label: t('admin.panel.tabWeeks'),
                            panel: (
                                <section
                                    aria-labelledby="admin-weeks-title"
                                    className="grid gap-4"
                                >
                                    <h2
                                        id="admin-weeks-title"
                                        className={sectionTitleClass}
                                    >
                                        {t('admin.panel.weeks')}
                                    </h2>
                                    <div className="grid gap-4 lg:grid-cols-2">
                                        <WeekCard
                                            heading={t('admin.panel.current')}
                                            week={weeks.current}
                                            locale={locale}
                                        />
                                        <WeekCard
                                            heading={t('admin.panel.next')}
                                            week={weeks.next}
                                            locale={locale}
                                        />
                                    </div>
                                </section>
                            ),
                        },
                        {
                            id: 'processes',
                            label: t('admin.panel.tabProcesses'),
                            dot: staleHeartbeats > 0,
                            indicatorLabel: t('admin.panel.processesIndicator'),
                            panel: (
                                <section
                                    aria-labelledby="admin-heartbeats-title"
                                    className={sectionClass}
                                >
                                    <h2
                                        id="admin-heartbeats-title"
                                        className={sectionTitleClass}
                                    >
                                        {t('admin.heartbeats.title')}
                                    </h2>
                                    <dl className="grid gap-4 text-sm sm:grid-cols-3">
                                        {heartbeats.map((heartbeat) => (
                                            <div
                                                key={heartbeat.source}
                                                className="grid content-start gap-1"
                                            >
                                                <dt className="flex flex-wrap items-center gap-2 font-semibold">
                                                    {t(
                                                        `admin.heartbeats.source.${heartbeat.source}`,
                                                    )}
                                                    <Badge
                                                        variant={
                                                            heartbeat.healthy
                                                                ? 'default'
                                                                : 'destructive'
                                                        }
                                                    >
                                                        {heartbeat.healthy
                                                            ? t(
                                                                  'admin.heartbeats.healthy',
                                                              )
                                                            : t(
                                                                  'admin.heartbeats.stale',
                                                              )}
                                                    </Badge>
                                                </dt>
                                                <dd className="text-muted">
                                                    {heartbeatAge(
                                                        t,
                                                        heartbeat.age_seconds,
                                                    )}
                                                </dd>
                                                {heartbeat.offsite ===
                                                null ? null : (
                                                    <dd className="text-muted">
                                                        {heartbeat.offsite
                                                            ? t(
                                                                  'admin.heartbeats.offsite',
                                                              )
                                                            : t(
                                                                  'admin.heartbeats.localOnly',
                                                              )}
                                                    </dd>
                                                )}
                                            </div>
                                        ))}
                                    </dl>
                                </section>
                            ),
                        },
                        {
                            id: 'sections',
                            label: t('admin.panel.tabSections'),
                            panel: (
                                <nav aria-label={t('admin.sections')}>
                                    <ul className="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                                        {sections.map((section) => (
                                            <li key={section.key}>
                                                <Link
                                                    href={section.href}
                                                    className={cardClass}
                                                >
                                                    <span className="inline-flex items-center gap-2 font-semibold">
                                                        <section.icon
                                                            className="size-4"
                                                            aria-hidden="true"
                                                        />
                                                        {section.title}
                                                    </span>
                                                    <span className="text-sm text-muted">
                                                        {section.sub}
                                                    </span>
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </nav>
                            ),
                        },
                    ]}
                />
            </div>
        </>
    );
}
