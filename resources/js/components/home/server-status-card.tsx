import { CircleHelp, Check, Copy, PowerOff, Wifi } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { CarLabel } from '@/components/car-label';
import { useT } from '@/hooks/use-t';
import { copyText } from '@/lib/clipboard';
import { formatDateTime } from '@/lib/datetime';
import type { ServerSessionKey, ServerStatus } from '@/lib/server-status';
import { styleRef } from '@/lib/style-ref';
import { cn } from '@/lib/utils';

const SESSION_KEYS: Record<ServerSessionKey, string> = {
    practice: 'status.practice',
    qualy: 'status.qualy',
    race: 'status.race',
};

const COPY_NOTICE_MS = 3000;

function Label({ children }: { children: string }) {
    return (
        <dt className="mb-1 text-xs font-semibold tracking-[0.07em] text-muted uppercase">
            {children}
        </dt>
    );
}

export function ServerStatusCard({
    status,
    cars,
    locale,
}: {
    status: ServerStatus;
    cars: string[];
    locale: string;
}) {
    const t = useT();
    const [copied, setCopied] = useState<'' | 'ok' | 'fail'>('');
    const [announcement, setAnnouncement] = useState('');
    const nameRef = useRef<HTMLElement>(null);
    const previous = useRef(status.status);
    const configured = status.track !== null;
    const stateLabel = {
        online: t('status.online'),
        offline: t('status.offline'),
        unavailable: t('status.unavailable'),
    }[status.status];

    useEffect(() => {
        if (previous.current !== status.status) {
            previous.current = status.status;
            setAnnouncement(t('status.announce', { state: stateLabel }));
        }
    }, [status.status, stateLabel, t]);

    useEffect(() => {
        if (copied === '') {
            return;
        }

        const id = setTimeout(() => setCopied(''), COPY_NOTICE_MS);

        return () => clearTimeout(id);
    }, [copied]);

    const copy = async () => {
        if (status.ingameName === null) {
            return;
        }

        if (await copyText(status.ingameName)) {
            setCopied('ok');

            return;
        }

        const node = nameRef.current;
        const selection = window.getSelection();

        if (node !== null && selection !== null) {
            const range = document.createRange();
            range.selectNodeContents(node);
            selection.removeAllRanges();
            selection.addRange(range);
        }

        setCopied('fail');
    };

    const Icon = {
        online: Wifi,
        offline: PowerOff,
        unavailable: CircleHelp,
    }[status.status];

    return (
        <div className="grid gap-4 rounded-md border border-border bg-bg p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <span
                    className={cn(
                        'inline-flex items-center gap-2 text-[15px] font-semibold',
                        status.status === 'online' && 'text-ok',
                        status.status === 'offline' && 'text-err',
                        status.status === 'unavailable' && 'text-warn',
                    )}
                >
                    {status.status === 'online' ? (
                        <span
                            aria-hidden="true"
                            className="status-pulse size-2.5 rounded-full bg-ok"
                        />
                    ) : null}
                    <Icon className="size-4" aria-hidden="true" />
                    {stateLabel}
                </span>
                {status.status === 'online' && status.players !== null ? (
                    <p className="flex items-baseline gap-2">
                        <b className="font-display text-4xl leading-none tracking-[-0.02em] tabular-nums">
                            {status.players}
                        </b>
                        <span className="text-sm text-muted">
                            {status.maxPlayers === null
                                ? t('status.pilots')
                                : t('status.pilotsOf', {
                                      total: status.maxPlayers,
                                  })}
                        </span>
                    </p>
                ) : null}
            </div>

            {status.status === 'online' &&
            status.players !== null &&
            status.maxPlayers !== null ? (
                <div
                    role="progressbar"
                    aria-label={t('status.seats')}
                    aria-valuemin={0}
                    aria-valuemax={status.maxPlayers}
                    aria-valuenow={Math.min(status.players, status.maxPlayers)}
                    className="grid gap-0.75"
                    ref={styleRef({
                        'grid-template-columns': `repeat(${status.maxPlayers}, minmax(0, 1fr))`,
                    })}
                >
                    {Array.from({ length: status.maxPlayers }, (_, index) => (
                        <i
                            key={index}
                            className={cn(
                                'h-2.5 rounded-xs border',
                                index < (status.players ?? 0)
                                    ? 'border-primary bg-primary'
                                    : 'border-border bg-surface-2',
                            )}
                        />
                    ))}
                </div>
            ) : null}

            {status.status === 'unavailable' && status.fetchedAt !== null ? (
                <p className="text-sm text-muted">
                    {t('status.lastData', {
                        time: formatDateTime(
                            new Date(status.fetchedAt),
                            locale,
                            { day: 'numeric', month: 'short' },
                        ),
                    })}
                </p>
            ) : null}

            <dl className="m-0 grid grid-cols-2 gap-3">
                <div className={cn('min-w-0', !configured && 'col-span-2')}>
                    <Label>{t('status.circuit')}</Label>
                    <dd className="m-0 font-medium">
                        {status.track ?? t('cal.tba')}
                    </dd>
                </div>
                {configured && status.session !== null ? (
                    <div className="min-w-0">
                        <Label>{t('status.session')}</Label>
                        <dd className="m-0 font-medium">
                            {t(SESSION_KEYS[status.session])}
                        </dd>
                    </div>
                ) : null}
                {configured && cars.length > 0 ? (
                    <div className="col-span-2 min-w-0">
                        <Label>{t('status.cars')}</Label>
                        <dd className="m-0 flex flex-wrap gap-1.5 font-medium">
                            {cars.map((car) => (
                                <CarLabel
                                    key={car}
                                    name={car}
                                    className="rounded-sm border border-border-strong px-2 py-0.5 text-sm"
                                />
                            ))}
                        </dd>
                    </div>
                ) : null}
                {status.ingameName !== null ? (
                    <div className="col-span-2 min-w-0">
                        <Label>{t('status.ingame')}</Label>
                        <dd className="m-0 flex flex-wrap items-stretch gap-2">
                            <code
                                ref={nameRef}
                                className="flex min-h-11 min-w-0 flex-[1_1_200px] items-center rounded-sm border border-border-strong bg-surface px-3 mono text-[13.5px] wrap-anywhere"
                            >
                                {status.ingameName}
                            </code>
                            <button
                                type="button"
                                onClick={copy}
                                className={cn(
                                    'inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border px-3.5 text-sm font-semibold transition-[filter] duration-150 ease-out',
                                    copied === 'ok'
                                        ? 'border-border-strong text-fg hover:bg-surface-2'
                                        : 'border-primary bg-primary text-on-primary hover:brightness-110',
                                )}
                            >
                                {copied === 'ok' ? (
                                    <Check
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <Copy
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                )}
                                {copied === 'ok'
                                    ? t('status.copied')
                                    : t('status.copy')}
                            </button>
                        </dd>
                        <p
                            role="status"
                            className="mt-1.5 min-h-5 text-[13px] text-muted"
                        >
                            {copied === 'fail' ? (
                                t('status.copyFail')
                            ) : copied === 'ok' ? (
                                <span className="sr-only">
                                    {t('status.copied')}
                                </span>
                            ) : null}
                        </p>
                    </div>
                ) : null}
            </dl>

            <p role="status" aria-live="polite" className="sr-only">
                {announcement}
            </p>
        </div>
    );
}
