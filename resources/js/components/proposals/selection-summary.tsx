import { CircleAlert, Plus, X } from 'lucide-react';
import { CarLabel } from '@/components/car-label';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';
import type { CatalogCar, CatalogTrack } from '@/types/proposals';
import { fieldErrorClass, primaryButtonClass } from '../account-styles';

const chipClass =
    'inline-flex min-h-11 flex-none items-center rounded-sm border border-primary bg-tint pl-3 text-sm';

const emptyChipClass =
    'inline-flex min-h-11 flex-none items-center rounded-sm border border-dashed border-border-strong px-3 text-sm text-muted';

const removeClass =
    'grid min-h-11 min-w-11 place-items-center rounded-sm text-muted transition-colors duration-150 ease-out hover:text-fg';

export function SelectionSummary({
    track,
    cars,
    errors,
    processing,
    onRemoveTrack,
    onRemoveCar,
}: {
    track: CatalogTrack | null;
    cars: CatalogCar[];
    errors: string[];
    processing: boolean;
    onRemoveTrack: () => void;
    onRemoveCar: (carId: number) => void;
}) {
    const t = useT();

    return (
        <div className="grid grid-cols-[minmax(0,1fr)] gap-2">
            {errors.map((message) => (
                <p
                    key={message}
                    role="alert"
                    className={cn(fieldErrorClass, 'flex items-center gap-1.5')}
                >
                    <CircleAlert
                        className="size-4 flex-none"
                        aria-hidden="true"
                    />
                    {message}
                </p>
            ))}
            <div className="flex items-center gap-3">
                <h2 className="sr-only md:not-sr-only md:font-display md:text-base md:font-bold">
                    {t('props.selection')}
                    <span className="ml-2 mono text-xs font-medium text-muted">
                        {cars.length}
                    </span>
                </h2>
                <ul
                    aria-label={t('props.selection')}
                    tabIndex={0}
                    className="flex min-w-0 flex-1 gap-2 overflow-x-auto md:flex-wrap md:overflow-visible"
                >
                    <li className={track ? chipClass : emptyChipClass}>
                        {track ? track.display : t('props.noTrackChosen')}
                        {track ? (
                            <button
                                type="button"
                                aria-label={t('props.remove', {
                                    name: track.display,
                                })}
                                onClick={onRemoveTrack}
                                className={removeClass}
                            >
                                <X className="size-4" aria-hidden="true" />
                            </button>
                        ) : null}
                    </li>
                    {cars.length === 0 ? (
                        <li className={emptyChipClass}>
                            {t('props.noCarsChosen')}
                        </li>
                    ) : (
                        cars.map((car) => (
                            <li key={car.id} className={chipClass}>
                                <CarLabel
                                    name={car.displayName}
                                    className="whitespace-nowrap"
                                />
                                <button
                                    type="button"
                                    aria-label={t('props.remove', {
                                        name: car.displayName,
                                    })}
                                    onClick={() => onRemoveCar(car.id)}
                                    className={removeClass}
                                >
                                    <X className="size-4" aria-hidden="true" />
                                </button>
                            </li>
                        ))
                    )}
                </ul>
                <button
                    type="submit"
                    disabled={processing}
                    className={cn(primaryButtonClass, 'min-h-12 flex-none')}
                >
                    <Plus className="size-[18px]" aria-hidden="true" />
                    {t('props.submit')}
                </button>
            </div>
        </div>
    );
}
