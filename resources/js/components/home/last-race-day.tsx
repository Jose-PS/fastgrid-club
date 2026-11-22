import { router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Minus, Zap } from 'lucide-react';
import { DriverName } from '@/components/home/driver-name';
import { EmptyPanel } from '@/components/home/home-section';
import { StatsPagination } from '@/components/home/stats-pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useT } from '@/hooks/use-t';
import type { Translate } from '@/hooks/use-t';
import { formatTime } from '@/lib/datetime';
import { homeStatsUrl } from '@/lib/home-stats';
import type {
    LastRaceDay as LastRaceDayData,
    RaceResultRow,
} from '@/lib/home-stats';
import { formatGap, formatLapTime } from '@/lib/lap-time';
import { cn } from '@/lib/utils';

const headClass =
    'px-3.5 text-xs font-semibold tracking-[0.06em] text-muted uppercase';
const cellClass = 'px-3.5 py-3';

function totalText(row: RaceResultRow, t: Translate): string {
    if (!row.classified) {
        return t('res.notClassified');
    }

    if (row.lapped) {
        return row.lapsBehind > 1
            ? t('res.lappedMany', { n: row.lapsBehind })
            : t('res.lapped');
    }

    if (row.position === 1 && row.totalMs !== null) {
        return formatLapTime(row.totalMs);
    }

    return row.gapMs === null ? '—' : formatGap(row.gapMs);
}

function PositionsGained({ value, t }: { value: number | null; t: Translate }) {
    if (value === null) {
        return null;
    }

    if (value === 0) {
        return (
            <span className="inline-flex items-center gap-1 text-muted">
                <Minus className="size-4" aria-hidden="true" />
                <span className="sr-only">{t('res.same')}</span>
            </span>
        );
    }

    const gained = value > 0;
    const Icon = gained ? ArrowUp : ArrowDown;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 mono',
                gained ? 'text-ok' : 'text-err',
            )}
        >
            <Icon className="size-4" aria-hidden="true" />
            {Math.abs(value)}
            <span className="sr-only">
                {gained ? t('res.gained') : t('res.lost')}
            </span>
        </span>
    );
}

export function LastRaceDay({
    data,
    titleId,
    locale,
}: {
    data: LastRaceDayData;
    titleId: string;
    locale: string;
}) {
    const t = useT();
    const { url } = usePage();
    const { heats, selectedHeatId, hasGrid, rows, pagination } = data;

    if (heats.length === 0) {
        return <EmptyPanel message={t('res.empty')} />;
    }

    const selectHeat = (value: string) => {
        if (value === '') {
            return;
        }

        router.get(
            homeStatsUrl(url, { res_heat: Number(value), res_page: null }),
            {},
            {
                only: ['lastRaceDay'],
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    };

    return (
        <div className="grid gap-4">
            <div className="grid gap-1.5">
                <span
                    id="res-pick"
                    className="text-[13px] font-semibold text-muted"
                >
                    {t('res.pick')}
                </span>
                <ToggleGroup
                    type="single"
                    spacing={1}
                    aria-labelledby="res-pick"
                    value={String(selectedHeatId ?? '')}
                    onValueChange={selectHeat}
                    className="flex-wrap rounded-sm border border-border bg-surface p-0.5"
                >
                    {heats.map((heat) => (
                        <ToggleGroupItem
                            key={heat.id}
                            value={String(heat.id)}
                            className="min-h-11"
                        >
                            {t('res.race')} {heat.number}
                            <span className="ml-1.5 mono">
                                {formatTime(new Date(heat.startsAt), locale)}
                            </span>
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </div>

            <div
                role="region"
                aria-labelledby={titleId}
                tabIndex={0}
                className="overflow-x-auto rounded-md border border-border bg-surface"
            >
                <Table
                    containerClassName="overflow-visible"
                    className="min-w-[36rem]"
                >
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className={cn(headClass, 'w-14')}>
                                {t('res.pos')}
                            </TableHead>
                            <TableHead className={headClass}>
                                {t('res.driver')}
                            </TableHead>
                            <TableHead className={cn(headClass, 'text-right')}>
                                {t('res.laps')}
                            </TableHead>
                            <TableHead className={cn(headClass, 'text-right')}>
                                {t('res.total')}
                            </TableHead>
                            <TableHead className={cn(headClass, 'text-right')}>
                                {t('res.fl')}
                            </TableHead>
                            {hasGrid ? (
                                <TableHead
                                    className={cn(headClass, 'text-right')}
                                >
                                    {t('res.gain')}
                                </TableHead>
                            ) : null}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow
                                key={`${row.position}-${row.driver}`}
                                className={cn(row.isYou && 'bg-tint')}
                            >
                                <TableCell
                                    className={cn(
                                        cellClass,
                                        'font-display text-lg font-bold',
                                    )}
                                >
                                    {row.position}
                                </TableCell>
                                <TableCell className={cellClass}>
                                    <DriverName
                                        name={row.driver}
                                        hidden={row.hidden}
                                        isYou={row.isYou}
                                    />
                                </TableCell>
                                <TableCell
                                    className={cn(cellClass, 'text-right mono')}
                                >
                                    {row.lapsCompleted}
                                </TableCell>
                                <TableCell
                                    className={cn(
                                        cellClass,
                                        'text-right mono',
                                        !row.classified && 'text-muted',
                                    )}
                                >
                                    {totalText(row, t)}
                                </TableCell>
                                <TableCell
                                    className={cn(cellClass, 'text-right')}
                                >
                                    {row.bestLapMs === null ? (
                                        <span className="mono text-muted">
                                            —
                                        </span>
                                    ) : row.isFastest ? (
                                        <span
                                            title={t('res.fastest')}
                                            className="inline-flex items-center gap-1.5 rounded-sm border border-info px-2 py-0.5 mono text-info"
                                        >
                                            <Zap
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            <span className="sr-only">
                                                {t('res.fastest')}:
                                            </span>
                                            {formatLapTime(row.bestLapMs)}
                                        </span>
                                    ) : (
                                        <span className="mono text-muted">
                                            {formatLapTime(row.bestLapMs)}
                                        </span>
                                    )}
                                </TableCell>
                                {hasGrid ? (
                                    <TableCell
                                        className={cn(cellClass, 'text-right')}
                                    >
                                        <PositionsGained
                                            value={row.positionsGained}
                                            t={t}
                                        />
                                    </TableCell>
                                ) : null}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            <StatsPagination
                prefix="res"
                only="lastRaceDay"
                pagination={pagination}
            />
        </div>
    );
}
