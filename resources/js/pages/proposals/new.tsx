import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Check } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { sectionClass } from '@/components/account-styles';
import { CarPicker } from '@/components/proposals/car-picker';
import { LockBlock } from '@/components/proposals/lock-block';
import { SelectionSummary } from '@/components/proposals/selection-summary';
import { Tabs } from '@/components/ui/tabs';
import { TrackPicker } from '@/components/proposals/track-picker';
import { usePublicRoutes } from '@/hooks/use-public-routes';
import { useT } from '@/hooks/use-t';
import { formatDate } from '@/lib/datetime';
import { hasValidCarCount } from '@/lib/proposal-form';
import { store } from '@/routes/proposals';
import type {
    Catalog,
    CatalogCar,
    CatalogTrack,
    CatalogViewer,
    ProposalWeek,
} from '@/types/proposals';

const SHORT_DATE: Intl.DateTimeFormatOptions = {
    day: 'numeric',
    month: 'short',
};

const DAY_MS = 86400000;

function weekRange(week: ProposalWeek, locale: string): string {
    const first = new Date(week.startsAt);
    const last = new Date(new Date(week.endsAt).getTime() - DAY_MS);

    return `${formatDate(first, locale, SHORT_DATE)} – ${formatDate(last, locale, SHORT_DATE)}`;
}

export default function NewProposal({
    week,
    viewer,
    catalog,
}: {
    week: ProposalWeek | null;
    viewer: CatalogViewer;
    catalog: Catalog;
}) {
    const t = useT();
    const { proposals } = usePublicRoutes();
    const { locale } = usePage<{ locale: string }>().props;
    const form = useForm<{ track_id: number | null; cars: number[] }>({
        track_id: null,
        cars: [],
    });
    const [activeTab, setActiveTab] = useState('tab-track');
    const isOpen = week?.isOpen === true;
    const canSubmit =
        isOpen && viewer.state === 'ready' && !viewer.proposalLimitReached;
    const errors: Record<string, string | undefined> = form.errors;
    const messages = [
        errors.track_id,
        errors.cars ?? errors['cars.0'],
        errors.proposal,
    ].filter((message): message is string => message !== undefined);
    const track: CatalogTrack | null =
        catalog.tracks.find((item) => item.id === form.data.track_id) ?? null;
    const cars = form.data.cars
        .map((carId) => catalog.cars.find((car) => car.id === carId))
        .filter((car): car is CatalogCar => car !== undefined);

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (form.data.track_id === null) {
            form.setError('track_id', t('props.errTrack'));

            return;
        }

        if (!hasValidCarCount(form.data.cars)) {
            form.setError('cars', t('props.errCars'));

            return;
        }

        form.clearErrors();
        form.post(store().url, { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('props.newTitle')} />
            <div className="grid grid-cols-[minmax(0,1fr)] gap-6">
                <div className="grid gap-2">
                    <Link
                        href={proposals().url}
                        className="inline-flex min-h-11 items-center gap-2 justify-self-start text-sm font-semibold text-muted transition-colors duration-150 ease-out hover:text-fg"
                    >
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        {t('props.back')}
                    </Link>
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('props.newTitle')}
                    </h1>
                    <p className="text-muted">
                        {t('props.newSub')}
                        {week
                            ? ` ${t('props.sub', { range: weekRange(week, locale) })}`
                            : ''}
                    </p>
                </div>

                {canSubmit ? (
                    <form
                        onSubmit={submit}
                        noValidate
                        aria-label={t('props.selection')}
                        className="sticky top-[calc(4rem+1px+env(safe-area-inset-top,0px))] z-20 rounded-md border border-border-strong bg-surface p-2.5"
                    >
                        <SelectionSummary
                            track={track}
                            cars={cars}
                            errors={messages}
                            processing={form.processing}
                            onRemoveTrack={() => form.setData('track_id', null)}
                            onRemoveCar={(carId) =>
                                form.setData(
                                    'cars',
                                    form.data.cars.filter((id) => id !== carId),
                                )
                            }
                        />
                    </form>
                ) : null}
                <Tabs
                    label={t('props.tabs')}
                    activeId={activeTab}
                    onChange={setActiveTab}
                    items={[
                        {
                            id: 'tab-track',
                            label: t('props.circuit'),
                            status:
                                canSubmit && track ? (
                                    <>
                                        <Check
                                            className="size-3 flex-none"
                                            aria-hidden="true"
                                        />
                                        <span className="truncate">
                                            {track.display}
                                        </span>
                                    </>
                                ) : null,
                            panel: (
                                <TrackPicker
                                    catalog={catalog}
                                    selectedId={form.data.track_id}
                                    selectable={canSubmit}
                                    onNext={() => setActiveTab('tab-cars')}
                                    onChange={(trackId) => {
                                        form.clearErrors('track_id');
                                        form.setData('track_id', trackId);
                                    }}
                                />
                            ),
                        },
                        {
                            id: 'tab-cars',
                            label: t('props.cars'),
                            status: canSubmit ? `${cars.length}` : null,
                            panel: (
                                <CarPicker
                                    catalog={catalog}
                                    selectedIds={form.data.cars}
                                    selectable={canSubmit}
                                    onChange={(carIds) => {
                                        form.clearErrors('cars');
                                        form.setData('cars', carIds);
                                    }}
                                />
                            ),
                        },
                    ]}
                />

                {!canSubmit ? (
                    <section className={sectionClass}>
                        {viewer.state !== 'ready' && isOpen ? (
                            <LockBlock state={viewer.state} />
                        ) : (
                            <p role="status" className="text-muted">
                                {week === null
                                    ? t('props.noWeek')
                                    : !isOpen
                                      ? t('props.closed')
                                      : t('props.quota')}
                            </p>
                        )}
                    </section>
                ) : null}
            </div>
        </>
    );
}
