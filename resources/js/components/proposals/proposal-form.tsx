import { useForm } from '@inertiajs/react';
import { CircleAlert, Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useT } from '@/hooks/use-t';
import { hasValidCarCount } from '@/lib/proposal-form';
import { cn } from '@/lib/utils';
import { store } from '@/routes/proposals';
import type { ProposalOptions } from '@/types/proposals';
import { fieldErrorClass, primaryButtonClass } from '../account-styles';
import { CarCombobox } from './car-combobox';
import { TrackCombobox } from './track-combobox';

export function ProposalForm({ options }: { options: ProposalOptions }) {
    const t = useT();
    const form = useForm<{ track_id: number | ''; cars: number[] }>({
        track_id: options.tracks[0]?.id ?? '',
        cars: [],
    });
    const errors: Record<string, string | undefined> = form.errors;
    const proposalError = errors.proposal;
    const carsError = form.errors.cars ?? form.errors['cars.0'];

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (!hasValidCarCount(form.data.cars)) {
            form.setError('cars', t('props.errCars'));

            return;
        }

        form.clearErrors();
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => form.setData('cars', []),
        });
    };

    return (
        <form onSubmit={submit} noValidate className="grid gap-4.5">
            <div className="grid gap-1.5">
                <label
                    htmlFor="proposal-track"
                    className="text-sm font-semibold"
                >
                    {t('props.circuit')}
                </label>
                <TrackCombobox
                    id="proposal-track"
                    tracks={options.tracks}
                    value={form.data.track_id}
                    invalid={Boolean(form.errors.track_id)}
                    onChange={(trackId) => {
                        form.clearErrors('track_id');
                        form.setData('track_id', trackId);
                    }}
                />
                {form.errors.track_id ? (
                    <p role="alert" className={fieldErrorClass}>
                        {form.errors.track_id}
                    </p>
                ) : null}
            </div>
            <div className="grid gap-1.5">
                <div className="flex flex-wrap items-baseline gap-x-3">
                    <label
                        id="proposal-cars-label"
                        htmlFor="proposal-cars"
                        className="text-sm font-semibold"
                    >
                        {t('props.cars')}
                    </label>
                    <span className="mono text-xs text-muted">
                        {form.data.cars.length} · {t('props.carsHint')}
                    </span>
                </div>
                <CarCombobox
                    id="proposal-cars"
                    labelId="proposal-cars-label"
                    cars={options.cars}
                    selectedIds={form.data.cars}
                    invalid={Boolean(carsError)}
                    onChange={(carIds) => {
                        form.clearErrors('cars');
                        form.setData('cars', carIds);
                    }}
                />
            </div>
            {carsError ? (
                <p
                    role="alert"
                    className={cn(fieldErrorClass, 'flex items-center gap-1.5')}
                >
                    <CircleAlert
                        className="size-4 flex-none"
                        aria-hidden="true"
                    />
                    {carsError}
                </p>
            ) : null}
            {proposalError ? (
                <p
                    role="alert"
                    className={cn(fieldErrorClass, 'flex items-center gap-1.5')}
                >
                    <CircleAlert
                        className="size-4 flex-none"
                        aria-hidden="true"
                    />
                    {proposalError}
                </p>
            ) : null}
            <button
                type="submit"
                disabled={form.processing || options.tracks.length === 0}
                className={cn(
                    primaryButtonClass,
                    'min-h-12 justify-self-start',
                )}
            >
                <Plus className="size-[18px]" aria-hidden="true" />
                {t('props.submit')}
            </button>
        </form>
    );
}
