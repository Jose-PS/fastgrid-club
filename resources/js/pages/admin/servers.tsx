import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { sectionClass, sectionTitleClass } from '@/components/account-styles';
import { HistoryPagination } from '@/components/admin/history-pagination';
import type { HistoryPage } from '@/components/admin/history-pagination';
import type { ServerSummary } from '@/components/admin/server-types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs } from '@/components/ui/tabs';
import { useT } from '@/hooks/use-t';
import { formatDateTime } from '@/lib/datetime';
import { showToast } from '@/lib/toast';
import { planning, reapply } from '@/routes/admin/servers';

type ServerDetail = ServerSummary & {
    id: number;
    name: string;
    latency_ms: number | null;
    last_poll_ok_at: string | null;
    circuit: { open: boolean; failures: number };
    cursor: { last_read_at: string | null; last_gap_at: string | null };
    ingest: {
        at: string | null;
        lines: number;
        laps: number;
        laps_discarded: number;
        laps_rejected: number;
    } | null;
    gaps_30d: number;
    versions: { panel: string | null; image: string | null };
};

type Application = {
    id: number;
    block_key: string | null;
    attempt: number;
    status: 'ok' | 'failed' | 'pending';
    error: string | null;
    started_at: string;
    finished_at: string | null;
};

type IntegrationEventRow = {
    id: number;
    type: string;
    summary: string;
    created_at: string | null;
};

const headClass =
    'px-3 text-xs font-semibold tracking-[0.06em] text-muted uppercase';
const cellClass = 'px-3 py-2.5 align-top';

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-muted">{label}</dt>
            <dd className="font-semibold">{children}</dd>
        </div>
    );
}

export default function AdminServers({
    server,
    applications,
    events,
}: {
    server: ServerDetail | null;
    applications: HistoryPage<Application> | null;
    events: HistoryPage<IntegrationEventRow> | null;
}) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const [busy, setBusy] = useState(false);

    const dateTime = (value: string | null): string =>
        value === null
            ? t('admin.servers.never')
            : formatDateTime(new Date(value), locale, {
                  day: 'numeric',
                  month: 'short',
              });

    const togglePlanning = (current: ServerDetail): void => {
        const message = current.planning_paused
            ? t('admin.servers.confirmResume')
            : t('admin.servers.confirmPause');

        if (!window.confirm(message)) {
            return;
        }

        router.put(
            planning(current.id).url,
            { paused: !current.planning_paused },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () =>
                    showToast({
                        type: 'success',
                        message: t('admin.servers.planningSaved'),
                    }),
            },
        );
    };

    const reapplyBlock = (current: ServerDetail): void => {
        const players =
            current.live.players === null
                ? t('admin.servers.playersUnknown')
                : String(current.live.players);

        if (!window.confirm(t('admin.servers.confirmReapply', { players }))) {
            return;
        }

        router.post(
            reapply(current.id).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <>
            <Head title={t('admin.servers.title')} />
            <div className="grid gap-7">
                <div>
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('admin.servers.title')}
                    </h1>
                    <p className="mt-2 max-w-[60ch] text-muted">
                        {t('admin.servers.sub')}
                    </p>
                </div>
                {server === null ? (
                    <p role="status" className="text-muted">
                        {t('admin.servers.empty')}
                    </p>
                ) : (
                    <>
                        <Tabs
                            label={t('admin.servers.tabsLabel')}
                            queryKey="tab"
                            defaultId="state"
                            items={[
                                {
                                    id: 'state',
                                    label: t('admin.servers.tabState'),
                                    dot: server.circuit.open,
                                    indicatorLabel: t(
                                        'admin.servers.tabCircuitOpen',
                                    ),
                                    panel: (
                                        <div className="grid gap-7">
                                            <section
                                                aria-labelledby="server-state-title"
                                                className={sectionClass}
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-3">
                                                    <h2
                                                        id="server-state-title"
                                                        className={
                                                            sectionTitleClass
                                                        }
                                                    >
                                                        {server.name}
                                                    </h2>
                                                    <Badge
                                                        variant={
                                                            server.live
                                                                .status ===
                                                            'online'
                                                                ? 'default'
                                                                : 'destructive'
                                                        }
                                                    >
                                                        {t(
                                                            `admin.servers.status.${server.live.status}`,
                                                        )}
                                                    </Badge>
                                                </div>
                                                <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                                    <Fact
                                                        label={t(
                                                            'admin.servers.players',
                                                        )}
                                                    >
                                                        {server.live.players ===
                                                        null
                                                            ? t(
                                                                  'admin.servers.playersUnknown',
                                                              )
                                                            : server.live
                                                                  .players}
                                                    </Fact>
                                                    <Fact
                                                        label={t(
                                                            'admin.servers.block',
                                                        )}
                                                    >
                                                        {t(
                                                            `admin.servers.block.${server.block}`,
                                                        )}
                                                    </Fact>
                                                    <Fact
                                                        label={t(
                                                            'admin.servers.snapshotAt',
                                                        )}
                                                    >
                                                        {server.live
                                                            .fetched_at === null
                                                            ? t(
                                                                  'admin.servers.unknown',
                                                              )
                                                            : dateTime(
                                                                  server.live
                                                                      .fetched_at,
                                                              )}
                                                    </Fact>
                                                    <Fact
                                                        label={t(
                                                            'admin.servers.latency',
                                                        )}
                                                    >
                                                        {server.latency_ms ===
                                                        null
                                                            ? t(
                                                                  'admin.servers.unknown',
                                                              )
                                                            : t(
                                                                  'admin.servers.latencyValue',
                                                                  {
                                                                      ms: server.latency_ms,
                                                                  },
                                                              )}
                                                    </Fact>
                                                    <Fact
                                                        label={t(
                                                            'admin.servers.lastPollOk',
                                                        )}
                                                    >
                                                        {dateTime(
                                                            server.last_poll_ok_at,
                                                        )}
                                                    </Fact>
                                                    <Fact
                                                        label={t(
                                                            'admin.servers.circuit',
                                                        )}
                                                    >
                                                        {t(
                                                            server.circuit.open
                                                                ? 'admin.servers.circuitOpen'
                                                                : 'admin.servers.circuitClosed',
                                                            {
                                                                n: server
                                                                    .circuit
                                                                    .failures,
                                                            },
                                                        )}
                                                    </Fact>
                                                    <Fact
                                                        label={t(
                                                            'admin.servers.lastApply',
                                                        )}
                                                    >
                                                        {server.last_apply ===
                                                        null
                                                            ? t(
                                                                  'admin.servers.never',
                                                              )
                                                            : `${t(`admin.servers.appStatus.${server.last_apply.status}`)} · ${dateTime(server.last_apply.finished_at ?? server.last_apply.started_at)}`}
                                                    </Fact>
                                                </dl>
                                            </section>
                                            <section
                                                aria-labelledby="server-logs-title"
                                                className={sectionClass}
                                            >
                                                <h2
                                                    id="server-logs-title"
                                                    className={
                                                        sectionTitleClass
                                                    }
                                                >
                                                    {t(
                                                        'admin.servers.logsTitle',
                                                    )}
                                                </h2>
                                                <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                                    <Fact
                                                        label={t(
                                                            'admin.servers.lastRead',
                                                        )}
                                                    >
                                                        {dateTime(
                                                            server.cursor
                                                                .last_read_at,
                                                        )}
                                                    </Fact>
                                                    <Fact
                                                        label={t(
                                                            'admin.servers.lastGap',
                                                        )}
                                                    >
                                                        {server.cursor
                                                            .last_gap_at ===
                                                        null
                                                            ? t(
                                                                  'admin.servers.noGap',
                                                              )
                                                            : dateTime(
                                                                  server.cursor
                                                                      .last_gap_at,
                                                              )}
                                                    </Fact>
                                                    <Fact
                                                        label={t(
                                                            'admin.servers.gaps',
                                                        )}
                                                    >
                                                        {server.gaps_30d}
                                                    </Fact>
                                                    {server.ingest ===
                                                    null ? null : (
                                                        <>
                                                            <Fact
                                                                label={t(
                                                                    'admin.servers.ingestLines',
                                                                )}
                                                            >
                                                                {
                                                                    server
                                                                        .ingest
                                                                        .lines
                                                                }
                                                            </Fact>
                                                            <Fact
                                                                label={t(
                                                                    'admin.servers.ingestLaps',
                                                                )}
                                                            >
                                                                {
                                                                    server
                                                                        .ingest
                                                                        .laps
                                                                }
                                                            </Fact>
                                                            <Fact
                                                                label={t(
                                                                    'admin.servers.ingestDiscarded',
                                                                )}
                                                            >
                                                                {
                                                                    server
                                                                        .ingest
                                                                        .laps_discarded
                                                                }
                                                            </Fact>
                                                            <Fact
                                                                label={t(
                                                                    'admin.servers.ingestRejected',
                                                                )}
                                                            >
                                                                {
                                                                    server
                                                                        .ingest
                                                                        .laps_rejected
                                                                }
                                                            </Fact>
                                                        </>
                                                    )}
                                                </dl>
                                                {server.ingest === null ? (
                                                    <p className="text-sm text-muted">
                                                        {t(
                                                            'admin.servers.ingestEmpty',
                                                        )}
                                                    </p>
                                                ) : null}
                                                <h3 className="font-display text-base font-bold">
                                                    {t(
                                                        'admin.servers.versionsTitle',
                                                    )}
                                                </h3>
                                                <dl className="grid gap-4 text-sm sm:grid-cols-2">
                                                    <Fact
                                                        label={t(
                                                            'admin.servers.panelVersion',
                                                        )}
                                                    >
                                                        {server.versions
                                                            .panel ??
                                                            t(
                                                                'admin.servers.unknown',
                                                            )}
                                                    </Fact>
                                                    <Fact
                                                        label={t(
                                                            'admin.servers.imageVersion',
                                                        )}
                                                    >
                                                        {server.versions
                                                            .image ??
                                                            t(
                                                                'admin.servers.unknown',
                                                            )}
                                                    </Fact>
                                                </dl>
                                            </section>
                                        </div>
                                    ),
                                },
                                {
                                    id: 'planning',
                                    label: t('admin.servers.tabPlanning'),
                                    dot: server.planning_paused,
                                    indicatorLabel: t(
                                        'admin.servers.tabPaused',
                                    ),
                                    panel: (
                                        <section
                                            aria-labelledby="server-planning-title"
                                            className={sectionClass}
                                        >
                                            <h2
                                                id="server-planning-title"
                                                className={sectionTitleClass}
                                            >
                                                {t(
                                                    'admin.servers.planningTitle',
                                                )}
                                            </h2>
                                            <p className="text-sm">
                                                {server.planning_paused
                                                    ? t(
                                                          'admin.servers.planningPaused',
                                                      )
                                                    : t(
                                                          'admin.servers.planningActive',
                                                      )}
                                            </p>
                                            <div>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        togglePlanning(server)
                                                    }
                                                >
                                                    {server.planning_paused
                                                        ? t(
                                                              'admin.servers.resume',
                                                          )
                                                        : t(
                                                              'admin.servers.pause',
                                                          )}
                                                </Button>
                                            </div>
                                            <h3 className="font-display text-base font-bold">
                                                {t(
                                                    'admin.servers.reapplyTitle',
                                                )}
                                            </h3>
                                            <p className="max-w-[60ch] text-sm text-muted">
                                                {t('admin.servers.reapplyHelp')}
                                            </p>
                                            <div>
                                                <Button
                                                    type="button"
                                                    variant="destructive"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        reapplyBlock(server)
                                                    }
                                                >
                                                    {busy
                                                        ? t(
                                                              'admin.servers.reapplying',
                                                          )
                                                        : t(
                                                              'admin.servers.reapply',
                                                          )}
                                                </Button>
                                            </div>
                                        </section>
                                    ),
                                },
                                {
                                    id: 'applications',
                                    label: t('admin.servers.tabApplications'),
                                    panel: (
                                        <section
                                            aria-labelledby="server-applications-title"
                                            className="grid gap-4"
                                        >
                                            <h2
                                                id="server-applications-title"
                                                className={sectionTitleClass}
                                            >
                                                {t(
                                                    'admin.servers.applicationsTitle',
                                                )}
                                            </h2>
                                            {applications === null ||
                                            applications.data.length === 0 ? (
                                                <p
                                                    role="status"
                                                    className="text-muted"
                                                >
                                                    {t(
                                                        'admin.servers.applicationsEmpty',
                                                    )}
                                                </p>
                                            ) : (
                                                <>
                                                    <div className="rounded-md border border-border">
                                                        <Table>
                                                            <TableHeader>
                                                                <TableRow>
                                                                    <TableHead
                                                                        className={
                                                                            headClass
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'admin.servers.colBlock',
                                                                        )}
                                                                    </TableHead>
                                                                    <TableHead
                                                                        className={
                                                                            headClass
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'admin.servers.colAttempt',
                                                                        )}
                                                                    </TableHead>
                                                                    <TableHead
                                                                        className={
                                                                            headClass
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'admin.servers.colStatus',
                                                                        )}
                                                                    </TableHead>
                                                                    <TableHead
                                                                        className={
                                                                            headClass
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'admin.servers.colStarted',
                                                                        )}
                                                                    </TableHead>
                                                                    <TableHead
                                                                        className={
                                                                            headClass
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'admin.servers.colFinished',
                                                                        )}
                                                                    </TableHead>
                                                                    <TableHead
                                                                        className={
                                                                            headClass
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'admin.servers.colError',
                                                                        )}
                                                                    </TableHead>
                                                                </TableRow>
                                                            </TableHeader>
                                                            <TableBody>
                                                                {applications.data.map(
                                                                    (
                                                                        application,
                                                                    ) => (
                                                                        <TableRow
                                                                            key={
                                                                                application.id
                                                                            }
                                                                        >
                                                                            <TableCell
                                                                                className={`${cellClass} mono`}
                                                                            >
                                                                                {application.block_key ??
                                                                                    '—'}
                                                                            </TableCell>
                                                                            <TableCell
                                                                                className={
                                                                                    cellClass
                                                                                }
                                                                            >
                                                                                {
                                                                                    application.attempt
                                                                                }
                                                                            </TableCell>
                                                                            <TableCell
                                                                                className={
                                                                                    cellClass
                                                                                }
                                                                            >
                                                                                <Badge
                                                                                    variant={
                                                                                        application.status ===
                                                                                        'failed'
                                                                                            ? 'destructive'
                                                                                            : 'secondary'
                                                                                    }
                                                                                >
                                                                                    {t(
                                                                                        `admin.servers.appStatus.${application.status}`,
                                                                                    )}
                                                                                </Badge>
                                                                            </TableCell>
                                                                            <TableCell
                                                                                className={`${cellClass} whitespace-nowrap`}
                                                                            >
                                                                                {dateTime(
                                                                                    application.started_at,
                                                                                )}
                                                                            </TableCell>
                                                                            <TableCell
                                                                                className={`${cellClass} whitespace-nowrap`}
                                                                            >
                                                                                {application.finished_at ===
                                                                                null
                                                                                    ? '—'
                                                                                    : dateTime(
                                                                                          application.finished_at,
                                                                                      )}
                                                                            </TableCell>
                                                                            <TableCell
                                                                                className={`${cellClass} whitespace-normal`}
                                                                            >
                                                                                {application.error ??
                                                                                    '—'}
                                                                            </TableCell>
                                                                        </TableRow>
                                                                    ),
                                                                )}
                                                            </TableBody>
                                                        </Table>
                                                    </div>
                                                    <HistoryPagination
                                                        page={applications}
                                                        only="applications"
                                                    />
                                                </>
                                            )}
                                        </section>
                                    ),
                                },
                                {
                                    id: 'events',
                                    label: t('admin.servers.tabEvents'),
                                    panel: (
                                        <section
                                            aria-labelledby="server-events-title"
                                            className="grid gap-4"
                                        >
                                            <h2
                                                id="server-events-title"
                                                className={sectionTitleClass}
                                            >
                                                {t('admin.servers.eventsTitle')}
                                            </h2>
                                            {events === null ||
                                            events.data.length === 0 ? (
                                                <p
                                                    role="status"
                                                    className="text-muted"
                                                >
                                                    {t(
                                                        'admin.servers.eventsEmpty',
                                                    )}
                                                </p>
                                            ) : (
                                                <>
                                                    <div className="rounded-md border border-border">
                                                        <Table>
                                                            <TableHeader>
                                                                <TableRow>
                                                                    <TableHead
                                                                        className={
                                                                            headClass
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'admin.servers.colDate',
                                                                        )}
                                                                    </TableHead>
                                                                    <TableHead
                                                                        className={
                                                                            headClass
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'admin.servers.colType',
                                                                        )}
                                                                    </TableHead>
                                                                    <TableHead
                                                                        className={
                                                                            headClass
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'admin.servers.colSummary',
                                                                        )}
                                                                    </TableHead>
                                                                </TableRow>
                                                            </TableHeader>
                                                            <TableBody>
                                                                {events.data.map(
                                                                    (event) => (
                                                                        <TableRow
                                                                            key={
                                                                                event.id
                                                                            }
                                                                        >
                                                                            <TableCell
                                                                                className={`${cellClass} whitespace-nowrap`}
                                                                            >
                                                                                {event.created_at ===
                                                                                null
                                                                                    ? '—'
                                                                                    : dateTime(
                                                                                          event.created_at,
                                                                                      )}
                                                                            </TableCell>
                                                                            <TableCell
                                                                                className={
                                                                                    cellClass
                                                                                }
                                                                            >
                                                                                {t(
                                                                                    `admin.servers.event.${event.type}`,
                                                                                )}
                                                                            </TableCell>
                                                                            <TableCell
                                                                                className={`${cellClass} whitespace-normal`}
                                                                            >
                                                                                {
                                                                                    event.summary
                                                                                }
                                                                            </TableCell>
                                                                        </TableRow>
                                                                    ),
                                                                )}
                                                            </TableBody>
                                                        </Table>
                                                    </div>
                                                    <HistoryPagination
                                                        page={events}
                                                        only="events"
                                                    />
                                                </>
                                            )}
                                        </section>
                                    ),
                                },
                            ]}
                        />
                    </>
                )}
            </div>
        </>
    );
}
