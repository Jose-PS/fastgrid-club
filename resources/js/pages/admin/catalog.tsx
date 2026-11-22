import { Head, router, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import {
    inputClass,
    sectionClass,
    sectionTitleClass,
    warningClass,
} from '@/components/account-styles';
import type { LastCatalogSync } from '@/components/admin/server-types';
import { ProposableSwitch } from '@/components/admin/proposable-switch';
import { Tabs } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useT } from '@/hooks/use-t';
import { formatDateTime } from '@/lib/datetime';
import { showToast } from '@/lib/toast';
import { index, sync } from '@/routes/admin/catalog';
import { proposable as carProposable } from '@/routes/admin/catalog/cars';
import { proposable as trackProposable } from '@/routes/admin/catalog/tracks';

type CatalogTrack = {
    id: number;
    display: string;
    layout: string;
    country: string | null;
    max_pit_slot: number;
    active: boolean;
    proposable: boolean;
    has_practice: boolean;
    has_svg: boolean;
};

type CatalogCar = {
    id: number;
    display_name: string;
    brand: string | null;
    classes: string[];
    active: boolean;
    proposable: boolean;
    is_mod: boolean;
};

type MissingSvgWeek = { when: 'current' | 'next'; track: string };

type TabId = 'tracks' | 'cars';
type StateFilter = 'all' | 'active' | 'inactive';
type YesNoFilter = 'all' | 'yes' | 'no';

const ALL = '__all__';

const matchesState = (active: boolean, filter: StateFilter): boolean =>
    filter === 'all' || (filter === 'active') === active;

const matchesYesNo = (value: boolean, filter: YesNoFilter): boolean =>
    filter === 'all' || (filter === 'yes') === value;

const cellClass = 'px-3 py-2 align-middle';
const headClass = 'px-3 py-2 text-left text-sm font-semibold text-muted';

export default function AdminCatalog({
    tracks,
    cars,
    missingSvgWeeks,
    canSync,
    lastSync,
}: {
    tracks: CatalogTrack[];
    cars: CatalogCar[];
    missingSvgWeeks: MissingSvgWeek[];
    canSync: boolean;
    lastSync: LastCatalogSync | null;
}) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const [syncing, setSyncing] = useState(false);
    const { url } = usePage();
    const [activeTab, setActiveTab] = useState<TabId>(
        new URLSearchParams(url.split('?')[1] ?? '').get('tab') === 'cars'
            ? 'cars'
            : 'tracks',
    );
    const [trackSearch, setTrackSearch] = useState('');
    const [trackState, setTrackState] = useState<StateFilter>('all');
    const [trackProposableFilter, setTrackProposable] =
        useState<YesNoFilter>('all');
    const [noSvg, setNoSvg] = useState(false);
    const [carSearch, setCarSearch] = useState('');
    const [carClass, setCarClass] = useState(ALL);
    const [carState, setCarState] = useState<StateFilter>('all');
    const [carProposableFilter, setCarProposable] =
        useState<YesNoFilter>('all');

    const trackNeedle = trackSearch.trim().toLowerCase();
    const visibleTracks = tracks.filter(
        (track) =>
            (trackNeedle === '' ||
                [track.display, track.layout, track.country ?? '']
                    .join(' ')
                    .toLowerCase()
                    .includes(trackNeedle)) &&
            matchesState(track.active, trackState) &&
            matchesYesNo(track.proposable, trackProposableFilter) &&
            (!noSvg || !track.has_svg),
    );

    const carClasses = [...new Set(cars.flatMap((car) => car.classes))].sort();
    const carNeedle = carSearch.trim().toLowerCase();
    const visibleCars = cars.filter(
        (car) =>
            (carNeedle === '' ||
                [car.display_name, car.brand ?? '']
                    .join(' ')
                    .toLowerCase()
                    .includes(carNeedle)) &&
            (carClass === ALL || car.classes.includes(carClass)) &&
            matchesState(car.active, carState) &&
            matchesYesNo(car.proposable, carProposableFilter),
    );

    const changeTab = (id: string): void => {
        const tab: TabId = id === 'cars' ? 'cars' : 'tracks';

        setActiveTab(tab);
        router.get(
            index({ query: { tab } }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const runSync = (): void => {
        if (!window.confirm(t('admin.catalog.syncConfirm'))) {
            return;
        }

        router.post(
            sync().url,
            {},
            {
                preserveScroll: true,
                onStart: () => setSyncing(true),
                onFinish: () => setSyncing(false),
                onError: (errors) =>
                    showToast({
                        type: 'error',
                        message: Object.values(errors)[0] ?? '',
                    }),
            },
        );
    };

    const yesNo = (value: boolean): string =>
        value ? t('admin.catalog.yes') : t('admin.catalog.no');

    return (
        <>
            <Head title={t('admin.catalog.title')} />
            <div className="grid gap-7">
                <div>
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('admin.catalog.title')}
                    </h1>
                    <p className="mt-2 max-w-[60ch] text-muted">
                        {t('admin.catalog.sub')}
                    </p>
                </div>
                <section
                    aria-labelledby="catalog-sync-title"
                    className={sectionClass}
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2
                            id="catalog-sync-title"
                            className={sectionTitleClass}
                        >
                            {t('admin.catalog.lastSyncTitle')}
                        </h2>
                        {canSync ? (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={syncing}
                                onClick={runSync}
                            >
                                {syncing
                                    ? t('admin.catalog.syncing')
                                    : t('admin.catalog.syncNow')}
                            </Button>
                        ) : null}
                    </div>
                    {lastSync === null ? (
                        <p role="status" className="text-sm text-muted">
                            {t('admin.catalog.lastSyncNever')}
                        </p>
                    ) : (
                        <ul className="grid gap-1 text-sm">
                            <li className="font-semibold">
                                {t('admin.catalog.lastSyncAt', {
                                    date: formatDateTime(
                                        new Date(lastSync.finished_at),
                                        locale,
                                        {
                                            day: 'numeric',
                                            month: 'short',
                                        },
                                    ),
                                })}
                            </li>
                            <li>
                                {t('admin.catalog.syncCars', {
                                    created: lastSync.cars.created,
                                    updated: lastSync.cars.updated,
                                    deactivated: lastSync.cars.deactivated,
                                })}
                            </li>
                            <li>
                                {t('admin.catalog.syncTracks', {
                                    created: lastSync.tracks.created,
                                    updated: lastSync.tracks.updated,
                                    deactivated: lastSync.tracks.deactivated,
                                })}
                            </li>
                            <li>
                                {t('admin.catalog.syncUnpaired', {
                                    n: lastSync.unpaired,
                                })}
                            </li>
                        </ul>
                    )}
                </section>
                {missingSvgWeeks.length === 0 ? null : (
                    <div role="status" className={warningClass}>
                        <div className="grid gap-0.5">
                            <p className="inline-flex items-center gap-2 font-semibold">
                                <TriangleAlert
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {t('admin.catalog.svgWarningTitle')}
                            </p>
                            {missingSvgWeeks.map((week) => (
                                <p
                                    key={week.when}
                                    className="text-sm font-normal"
                                >
                                    {t(
                                        week.when === 'current'
                                            ? 'admin.catalog.svgWarningCurrent'
                                            : 'admin.catalog.svgWarningNext',
                                        { track: week.track },
                                    )}
                                </p>
                            ))}
                        </div>
                    </div>
                )}
                <Tabs
                    label={t('admin.catalog.tabsLabel')}
                    activeId={activeTab}
                    onChange={changeTab}
                    items={[
                        {
                            id: 'tracks',
                            label: t('admin.catalog.tracks'),
                            status: `${visibleTracks.length}/${tracks.length}`,
                            panel: (
                                <section
                                    aria-labelledby="catalog-tracks-title"
                                    className={sectionClass}
                                >
                                    <h2
                                        id="catalog-tracks-title"
                                        className="sr-only"
                                    >
                                        {t('admin.catalog.tracks')}
                                    </h2>
                                    <div className="flex flex-wrap items-end gap-3">
                                        <div className="grid gap-1.5">
                                            <label
                                                htmlFor="catalog-track-search"
                                                className="text-sm font-semibold"
                                            >
                                                {t(
                                                    'admin.catalog.filterTracks',
                                                )}
                                            </label>
                                            <input
                                                id="catalog-track-search"
                                                type="search"
                                                autoComplete="off"
                                                value={trackSearch}
                                                aria-describedby="catalog-track-search-help"
                                                onChange={(event) =>
                                                    setTrackSearch(
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                            <p
                                                id="catalog-track-search-help"
                                                className="text-[13px] text-muted"
                                            >
                                                {t(
                                                    'admin.catalog.filterTracksHelp',
                                                )}
                                            </p>
                                        </div>
                                        <div className="grid gap-1.5">
                                            <label
                                                htmlFor="catalog-track-state"
                                                className="text-sm font-semibold"
                                            >
                                                {t('admin.catalog.filterState')}
                                            </label>
                                            <Select
                                                value={trackState}
                                                onValueChange={(value) =>
                                                    setTrackState(
                                                        value as StateFilter,
                                                    )
                                                }
                                            >
                                                <SelectTrigger id="catalog-track-state">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="all">
                                                        {t(
                                                            'admin.catalog.filterAll',
                                                        )}
                                                    </SelectItem>
                                                    <SelectItem value="active">
                                                        {t(
                                                            'admin.catalog.active',
                                                        )}
                                                    </SelectItem>
                                                    <SelectItem value="inactive">
                                                        {t(
                                                            'admin.catalog.inactive',
                                                        )}
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="grid gap-1.5">
                                            <label
                                                htmlFor="catalog-track-proposable"
                                                className="text-sm font-semibold"
                                            >
                                                {t(
                                                    'admin.catalog.filterProposable',
                                                )}
                                            </label>
                                            <Select
                                                value={trackProposableFilter}
                                                onValueChange={(value) =>
                                                    setTrackProposable(
                                                        value as YesNoFilter,
                                                    )
                                                }
                                            >
                                                <SelectTrigger id="catalog-track-proposable">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="all">
                                                        {t(
                                                            'admin.catalog.filterAll',
                                                        )}
                                                    </SelectItem>
                                                    <SelectItem value="yes">
                                                        {t(
                                                            'admin.catalog.proposableYes',
                                                        )}
                                                    </SelectItem>
                                                    <SelectItem value="no">
                                                        {t(
                                                            'admin.catalog.proposableNo',
                                                        )}
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <label className="inline-flex min-h-11 items-center gap-2 text-sm font-semibold">
                                            <input
                                                type="checkbox"
                                                checked={noSvg}
                                                onChange={(event) =>
                                                    setNoSvg(
                                                        event.target.checked,
                                                    )
                                                }
                                                className="size-4"
                                            />
                                            {t('admin.catalog.filterNoSvg')}
                                        </label>
                                    </div>
                                    {visibleTracks.length === 0 ? (
                                        <p role="status" className="text-muted">
                                            {t('admin.catalog.emptyTracks')}
                                        </p>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full min-w-[56rem] border-collapse text-sm">
                                                <thead>
                                                    <tr className="border-b border-border">
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colTrack',
                                                            )}
                                                        </th>
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colLayout',
                                                            )}
                                                        </th>
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colCountry',
                                                            )}
                                                        </th>
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colState',
                                                            )}
                                                        </th>
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colPit',
                                                            )}
                                                        </th>
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colPractice',
                                                            )}
                                                        </th>
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colSvg',
                                                            )}
                                                        </th>
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colProposable',
                                                            )}
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {visibleTracks.map(
                                                        (track) => (
                                                            <tr
                                                                key={track.id}
                                                                className="border-b border-border last:border-b-0"
                                                            >
                                                                <th
                                                                    scope="row"
                                                                    className={`${cellClass} text-left font-semibold`}
                                                                >
                                                                    {
                                                                        track.display
                                                                    }
                                                                </th>
                                                                <td
                                                                    className={
                                                                        cellClass
                                                                    }
                                                                >
                                                                    {
                                                                        track.layout
                                                                    }
                                                                </td>
                                                                <td
                                                                    className={
                                                                        cellClass
                                                                    }
                                                                >
                                                                    {track.country ??
                                                                        '—'}
                                                                </td>
                                                                <td
                                                                    className={
                                                                        cellClass
                                                                    }
                                                                >
                                                                    {track.active
                                                                        ? t(
                                                                              'admin.catalog.active',
                                                                          )
                                                                        : t(
                                                                              'admin.catalog.inactive',
                                                                          )}
                                                                </td>
                                                                <td
                                                                    className={
                                                                        cellClass
                                                                    }
                                                                >
                                                                    <span className="mono">
                                                                        {
                                                                            track.max_pit_slot
                                                                        }
                                                                    </span>
                                                                </td>
                                                                <td
                                                                    className={
                                                                        cellClass
                                                                    }
                                                                >
                                                                    {yesNo(
                                                                        track.has_practice,
                                                                    )}
                                                                </td>
                                                                <td
                                                                    className={
                                                                        cellClass
                                                                    }
                                                                >
                                                                    {track.has_svg
                                                                        ? t(
                                                                              'admin.catalog.withSvg',
                                                                          )
                                                                        : t(
                                                                              'admin.catalog.withoutSvg',
                                                                          )}
                                                                </td>
                                                                <td
                                                                    className={
                                                                        cellClass
                                                                    }
                                                                >
                                                                    <ProposableSwitch
                                                                        url={
                                                                            trackProposable(
                                                                                track.id,
                                                                            )
                                                                                .url
                                                                        }
                                                                        checked={
                                                                            track.proposable
                                                                        }
                                                                        disabled={
                                                                            !track.active
                                                                        }
                                                                        label={t(
                                                                            'admin.catalog.toggleTrack',
                                                                            {
                                                                                name: track.display,
                                                                            },
                                                                        )}
                                                                    />
                                                                </td>
                                                            </tr>
                                                        ),
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </section>
                            ),
                        },
                        {
                            id: 'cars',
                            label: t('admin.catalog.cars'),
                            status: `${visibleCars.length}/${cars.length}`,
                            panel: (
                                <section
                                    aria-labelledby="catalog-cars-title"
                                    className={sectionClass}
                                >
                                    <h2
                                        id="catalog-cars-title"
                                        className="sr-only"
                                    >
                                        {t('admin.catalog.cars')}
                                    </h2>
                                    <div className="flex flex-wrap items-end gap-3">
                                        <div className="grid gap-1.5">
                                            <label
                                                htmlFor="catalog-car-search"
                                                className="text-sm font-semibold"
                                            >
                                                {t('admin.catalog.filterCars')}
                                            </label>
                                            <input
                                                id="catalog-car-search"
                                                type="search"
                                                autoComplete="off"
                                                value={carSearch}
                                                aria-describedby="catalog-car-search-help"
                                                onChange={(event) =>
                                                    setCarSearch(
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                            <p
                                                id="catalog-car-search-help"
                                                className="text-[13px] text-muted"
                                            >
                                                {t(
                                                    'admin.catalog.filterCarsHelp',
                                                )}
                                            </p>
                                        </div>
                                        <div className="grid gap-1.5">
                                            <label
                                                htmlFor="catalog-car-class"
                                                className="text-sm font-semibold"
                                            >
                                                {t('admin.catalog.filterClass')}
                                            </label>
                                            <Select
                                                value={carClass}
                                                onValueChange={setCarClass}
                                            >
                                                <SelectTrigger id="catalog-car-class">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={ALL}>
                                                        {t(
                                                            'admin.catalog.filterAllClasses',
                                                        )}
                                                    </SelectItem>
                                                    {carClasses.map((name) => (
                                                        <SelectItem
                                                            key={name}
                                                            value={name}
                                                        >
                                                            {name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="grid gap-1.5">
                                            <label
                                                htmlFor="catalog-car-state"
                                                className="text-sm font-semibold"
                                            >
                                                {t('admin.catalog.filterState')}
                                            </label>
                                            <Select
                                                value={carState}
                                                onValueChange={(value) =>
                                                    setCarState(
                                                        value as StateFilter,
                                                    )
                                                }
                                            >
                                                <SelectTrigger id="catalog-car-state">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="all">
                                                        {t(
                                                            'admin.catalog.filterAll',
                                                        )}
                                                    </SelectItem>
                                                    <SelectItem value="active">
                                                        {t(
                                                            'admin.catalog.active',
                                                        )}
                                                    </SelectItem>
                                                    <SelectItem value="inactive">
                                                        {t(
                                                            'admin.catalog.inactive',
                                                        )}
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="grid gap-1.5">
                                            <label
                                                htmlFor="catalog-car-proposable"
                                                className="text-sm font-semibold"
                                            >
                                                {t(
                                                    'admin.catalog.filterProposable',
                                                )}
                                            </label>
                                            <Select
                                                value={carProposableFilter}
                                                onValueChange={(value) =>
                                                    setCarProposable(
                                                        value as YesNoFilter,
                                                    )
                                                }
                                            >
                                                <SelectTrigger id="catalog-car-proposable">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="all">
                                                        {t(
                                                            'admin.catalog.filterAll',
                                                        )}
                                                    </SelectItem>
                                                    <SelectItem value="yes">
                                                        {t(
                                                            'admin.catalog.proposableYes',
                                                        )}
                                                    </SelectItem>
                                                    <SelectItem value="no">
                                                        {t(
                                                            'admin.catalog.proposableNo',
                                                        )}
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                    {visibleCars.length === 0 ? (
                                        <p role="status" className="text-muted">
                                            {t('admin.catalog.emptyCars')}
                                        </p>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full min-w-[40rem] border-collapse text-sm">
                                                <thead>
                                                    <tr className="border-b border-border">
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colCar',
                                                            )}
                                                        </th>
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colBrand',
                                                            )}
                                                        </th>
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colClasses',
                                                            )}
                                                        </th>
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colState',
                                                            )}
                                                        </th>
                                                        <th
                                                            scope="col"
                                                            className={
                                                                headClass
                                                            }
                                                        >
                                                            {t(
                                                                'admin.catalog.colProposable',
                                                            )}
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {visibleCars.map((car) => (
                                                        <tr
                                                            key={car.id}
                                                            className="border-b border-border last:border-b-0"
                                                        >
                                                            <th
                                                                scope="row"
                                                                className={`${cellClass} text-left font-semibold`}
                                                            >
                                                                {
                                                                    car.display_name
                                                                }
                                                            </th>
                                                            <td
                                                                className={
                                                                    cellClass
                                                                }
                                                            >
                                                                {car.brand ??
                                                                    '—'}
                                                            </td>
                                                            <td
                                                                className={
                                                                    cellClass
                                                                }
                                                            >
                                                                {car.classes.join(
                                                                    ', ',
                                                                )}
                                                            </td>
                                                            <td
                                                                className={
                                                                    cellClass
                                                                }
                                                            >
                                                                {car.active
                                                                    ? t(
                                                                          'admin.catalog.active',
                                                                      )
                                                                    : t(
                                                                          'admin.catalog.inactive',
                                                                      )}
                                                            </td>
                                                            <td
                                                                className={
                                                                    cellClass
                                                                }
                                                            >
                                                                <ProposableSwitch
                                                                    url={
                                                                        carProposable(
                                                                            car.id,
                                                                        ).url
                                                                    }
                                                                    checked={
                                                                        car.proposable
                                                                    }
                                                                    disabled={
                                                                        !car.active
                                                                    }
                                                                    label={t(
                                                                        'admin.catalog.toggleCar',
                                                                        {
                                                                            name: car.display_name,
                                                                        },
                                                                    )}
                                                                />
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </section>
                            ),
                        },
                    ]}
                />
            </div>
        </>
    );
}
