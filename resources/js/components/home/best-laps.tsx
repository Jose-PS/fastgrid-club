import { router, usePage } from '@inertiajs/react';
import { EmptyPanel } from '@/components/home/home-section';
import { DriverName } from '@/components/home/driver-name';
import { StatsPagination } from '@/components/home/stats-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useT } from '@/hooks/use-t';
import { formatDate } from '@/lib/datetime';
import { ALL_OPTION, homeStatsUrl } from '@/lib/home-stats';
import type { BestLaps as BestLapsData } from '@/lib/home-stats';
import { formatGap, formatLapTime } from '@/lib/lap-time';
import { cn } from '@/lib/utils';

const headClass =
    'px-3.5 text-xs font-semibold tracking-[0.06em] text-muted uppercase';
const cellClass = 'px-3.5 py-3';

export function BestLaps({
    data,
    titleId,
    locale,
}: {
    data: BestLapsData;
    titleId: string;
    locale: string;
}) {
    const t = useT();
    const { url } = usePage();
    const { options, selected, rows, pagination } = data;
    const showTrack = selected.trackId === null;
    const showGap = rows.some((row) => row.gapMs !== null);

    const visit = (changes: Record<string, number | null>) => {
        router.get(
            homeStatsUrl(url, { lb_page: null, ...changes }),
            {},
            {
                only: ['bestLaps'],
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    };

    const toValue = (value: string): number | null =>
        value === ALL_OPTION ? null : Number(value);

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-end gap-4">
                <div className="grid gap-1.5">
                    <label
                        htmlFor="lb-track"
                        className="text-[13px] font-semibold text-muted"
                    >
                        {t('lb.circuit')}
                    </label>
                    <Select
                        value={String(selected.trackId ?? ALL_OPTION)}
                        onValueChange={(value) =>
                            visit({
                                lb_track: toValue(value),
                                lb_car: selected.carId,
                            })
                        }
                    >
                        <SelectTrigger id="lb-track" className="min-w-56">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL_OPTION}>
                                {t('lb.all')}
                            </SelectItem>
                            {options.tracks.map((track) => (
                                <SelectItem
                                    key={track.id}
                                    value={String(track.id)}
                                >
                                    {track.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-1.5">
                    <label
                        htmlFor="lb-car"
                        className="text-[13px] font-semibold text-muted"
                    >
                        {t('lb.car')}
                    </label>
                    <Select
                        value={String(selected.carId ?? ALL_OPTION)}
                        onValueChange={(value) =>
                            visit({
                                lb_track: selected.trackId,
                                lb_car: toValue(value),
                            })
                        }
                    >
                        <SelectTrigger id="lb-car" className="min-w-56">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL_OPTION}>
                                {t('lb.all')}
                            </SelectItem>
                            {options.cars.map((car) => (
                                <SelectItem key={car.id} value={String(car.id)}>
                                    {car.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            {rows.length === 0 ? (
                <EmptyPanel message={t('lb.empty')} />
            ) : (
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
                                    {t('lb.pos')}
                                </TableHead>
                                <TableHead className={headClass}>
                                    {t('lb.driver')}
                                </TableHead>
                                {showTrack ? (
                                    <TableHead className={headClass}>
                                        {t('lb.circuit')}
                                    </TableHead>
                                ) : null}
                                <TableHead className={headClass}>
                                    {t('lb.carCol')}
                                </TableHead>
                                <TableHead
                                    className={cn(headClass, 'text-right')}
                                >
                                    {t('lb.time')}
                                </TableHead>
                                {showGap ? (
                                    <TableHead
                                        className={cn(headClass, 'text-right')}
                                    >
                                        {t('lb.gap')}
                                    </TableHead>
                                ) : null}
                                <TableHead
                                    className={cn(headClass, 'text-right')}
                                >
                                    {t('lb.date')}
                                </TableHead>
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
                                    {showTrack ? (
                                        <TableCell className={cellClass}>
                                            {row.track}
                                        </TableCell>
                                    ) : null}
                                    <TableCell
                                        className={cn(cellClass, 'text-muted')}
                                    >
                                        {row.car}
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            cellClass,
                                            'text-right mono',
                                        )}
                                    >
                                        {formatLapTime(row.lapMs)}
                                    </TableCell>
                                    {showGap ? (
                                        <TableCell
                                            className={cn(
                                                cellClass,
                                                'text-right mono text-muted',
                                            )}
                                        >
                                            {row.gapMs === null ||
                                            row.gapMs === 0
                                                ? '—'
                                                : formatGap(row.gapMs)}
                                        </TableCell>
                                    ) : null}
                                    <TableCell
                                        className={cn(
                                            cellClass,
                                            'text-right text-muted',
                                        )}
                                    >
                                        {formatDate(
                                            new Date(row.achievedAt),
                                            locale,
                                            { day: 'numeric', month: 'short' },
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <StatsPagination
                prefix="lb"
                only="bestLaps"
                pagination={pagination}
            />
        </div>
    );
}
