import { Head, useForm } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import {
    dangerButtonClass,
    fieldErrorClass,
    inputClass,
    primaryButtonClass,
    sectionClass,
} from '@/components/account-styles';
import { CarCombobox } from '@/components/proposals/car-combobox';
import { TrackCombobox } from '@/components/proposals/track-combobox';
import { useT } from '@/hooks/use-t';
import { hasValidCarCount } from '@/lib/proposal-form';
import { showToast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { destroy, update } from '@/routes/admin/default-config';
import type { ProposalOptions } from '@/types/proposals';

type DefaultConfig = {
    track_id: number | null;
    car_ids: number[];
    custom_title: string | null;
};

type DefaultConfigForm = {
    track_id: number | '';
    cars: number[];
    custom_title: string;
};

export default function AdminDefaultConfig({
    options,
    config,
    server,
}: {
    options: ProposalOptions;
    config: DefaultConfig | null;
    server: { name: string };
}) {
    const t = useT();
    const form = useForm<DefaultConfigForm>({
        track_id: config?.track_id ?? '',
        cars: config?.car_ids ?? [],
        custom_title: config?.custom_title ?? '',
    });
    const errors: Record<string, string | undefined> = form.errors;
    const carsError = errors.cars ?? errors['cars.0'];

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

        form.clearErrors();
        form.put(update().url, {
            preserveScroll: true,
            onSuccess: () =>
                showToast({
                    type: 'success',
                    message: t('admin.defaultConfig.saved'),
                }),
        });
    };

    const remove = (): void => {
        if (!window.confirm(t('admin.defaultConfig.confirmDelete'))) {
            return;
        }

        form.delete(destroy().url, {
            preserveScroll: true,
            onSuccess: () => {
                form.setData({ track_id: '', cars: [], custom_title: '' });
                showToast({
                    type: 'success',
                    message: t('admin.defaultConfig.deleted'),
                });
            },
        });
    };

    return (
        <>
            <Head title={t('admin.defaultConfig.title')} />
            <div className="grid gap-7">
                <div>
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('admin.defaultConfig.title')}
                    </h1>
                    <p className="mt-2 max-w-[60ch] text-muted">
                        {t('admin.defaultConfig.sub')}
                    </p>
                    <p className="mt-1 text-sm text-muted">
                        {t('admin.defaultConfig.server', { name: server.name })}
                    </p>
                </div>
                {config === null ? (
                    <p role="status" className="text-muted">
                        {t('admin.defaultConfig.none')}
                    </p>
                ) : null}
                <form
                    onSubmit={submit}
                    noValidate
                    className={cn(sectionClass, 'max-w-2xl')}
                >
                    <div className="grid gap-1.5">
                        <label
                            htmlFor="default-track"
                            className="text-sm font-semibold"
                        >
                            {t('props.circuit')}
                        </label>
                        <TrackCombobox
                            id="default-track"
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
                                id="default-cars-label"
                                htmlFor="default-cars"
                                className="text-sm font-semibold"
                            >
                                {t('props.cars')}
                            </label>
                            <span className="mono text-xs text-muted">
                                {form.data.cars.length} · {t('props.carsHint')}
                            </span>
                        </div>
                        <CarCombobox
                            id="default-cars"
                            labelId="default-cars-label"
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
                            htmlFor="default-title"
                            className="text-sm font-semibold"
                        >
                            {t('admin.defaultConfig.titleLabel')}
                        </label>
                        <input
                            id="default-title"
                            type="text"
                            autoComplete="off"
                            value={form.data.custom_title}
                            aria-invalid={Boolean(errors.custom_title)}
                            aria-describedby={`default-title-help${errors.custom_title ? ' default-title-error' : ''}`}
                            onChange={(event) => {
                                form.clearErrors('custom_title');
                                form.setData(
                                    'custom_title',
                                    event.target.value,
                                );
                            }}
                            className={inputClass}
                        />
                        <p
                            id="default-title-help"
                            className="text-[13px] text-muted"
                        >
                            {t('admin.defaultConfig.titleHelp')}
                        </p>
                        {errors.custom_title ? (
                            <p
                                id="default-title-error"
                                role="alert"
                                className={fieldErrorClass}
                            >
                                {errors.custom_title}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className={cn(primaryButtonClass, 'min-h-12')}
                        >
                            {t('admin.defaultConfig.save')}
                        </button>
                        {config === null ? null : (
                            <button
                                type="button"
                                onClick={remove}
                                disabled={form.processing}
                                className={cn(dangerButtonClass, 'min-h-12')}
                            >
                                {t('admin.defaultConfig.delete')}
                            </button>
                        )}
                    </div>
                </form>
            </div>
        </>
    );
}
