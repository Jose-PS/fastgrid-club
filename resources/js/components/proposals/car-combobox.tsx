import { Check, ChevronsUpDown, X } from 'lucide-react';
import { useState } from 'react';
import { CarLabel } from '@/components/car-label';
import { useT } from '@/hooks/use-t';
import { toggleCar } from '@/lib/proposal-form';
import { searchScore } from '@/lib/search';
import { cn } from '@/lib/utils';
import type { ProposalCar } from '@/types/proposals';
import { inputClass } from '../account-styles';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '../ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '../ui/popover';

export function CarCombobox({
    id,
    labelId,
    cars,
    selectedIds,
    invalid,
    onChange,
}: {
    id: string;
    labelId: string;
    cars: ProposalCar[];
    selectedIds: number[];
    invalid: boolean;
    onChange: (carIds: number[]) => void;
}) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const listId = `${id}-list`;
    const selectedCars = selectedIds
        .map((carId) => cars.find((car) => car.id === carId))
        .filter((car): car is ProposalCar => car !== undefined);

    return (
        <div className="grid gap-2.5">
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <button
                        id={id}
                        type="button"
                        role="combobox"
                        aria-expanded={open}
                        aria-controls={listId}
                        aria-labelledby={labelId}
                        aria-invalid={invalid ? true : undefined}
                        className={cn(
                            inputClass,
                            'flex items-center justify-between gap-2 text-left text-muted',
                        )}
                    >
                        <span className="min-w-0 truncate">
                            {t('props.pickCars')}
                        </span>
                        <ChevronsUpDown
                            className="size-4 flex-none"
                            aria-hidden="true"
                        />
                    </button>
                </PopoverTrigger>
                <PopoverContent>
                    <Command
                        filter={(itemValue, search, keywords) =>
                            searchScore(keywords?.[0] ?? itemValue, search)
                        }
                    >
                        <CommandInput
                            placeholder={t('props.searchCars')}
                            aria-label={t('props.cars')}
                        />
                        <CommandList id={listId} aria-multiselectable="true">
                            <CommandEmpty>{t('props.noResults')}</CommandEmpty>
                            {cars.map((car) => {
                                const selected = selectedIds.includes(car.id);

                                return (
                                    <CommandItem
                                        key={car.id}
                                        value={String(car.id)}
                                        keywords={[car.displayName]}
                                        onSelect={() =>
                                            onChange(
                                                toggleCar(selectedIds, car.id),
                                            )
                                        }
                                    >
                                        <Check
                                            className={cn(
                                                'size-4',
                                                selected
                                                    ? 'text-primary-ink'
                                                    : 'invisible',
                                            )}
                                            aria-hidden="true"
                                        />
                                        <CarLabel
                                            name={car.displayName}
                                            className="flex-1"
                                        />
                                    </CommandItem>
                                );
                            })}
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            {selectedCars.length > 0 ? (
                <ul
                    aria-label={t('props.selectedCars')}
                    className="flex flex-wrap gap-2"
                >
                    {selectedCars.map((car) => (
                        <li
                            key={car.id}
                            className="inline-flex min-h-11 items-center rounded-sm border border-primary bg-tint pl-3 text-sm"
                        >
                            <CarLabel name={car.displayName} />
                            <button
                                type="button"
                                aria-label={t('props.remove', {
                                    name: car.displayName,
                                })}
                                onClick={() =>
                                    onChange(toggleCar(selectedIds, car.id))
                                }
                                className="grid min-h-11 min-w-11 place-items-center rounded-sm text-muted transition-colors duration-150 ease-out hover:text-fg"
                            >
                                <X className="size-4" aria-hidden="true" />
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
