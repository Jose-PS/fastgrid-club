import { Check, ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';
import { useT } from '@/hooks/use-t';
import { searchScore } from '@/lib/search';
import { cn } from '@/lib/utils';
import type { ProposalTrack } from '@/types/proposals';
import { inputClass } from '../account-styles';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '../ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '../ui/popover';

export function TrackCombobox({
    id,
    tracks,
    value,
    invalid,
    onChange,
}: {
    id: string;
    tracks: ProposalTrack[];
    value: number | '';
    invalid: boolean;
    onChange: (trackId: number) => void;
}) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const selected = tracks.find((track) => track.id === value);
    const listId = `${id}-list`;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    id={id}
                    type="button"
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-invalid={invalid ? true : undefined}
                    disabled={tracks.length === 0}
                    className={cn(
                        inputClass,
                        'flex items-center justify-between gap-2 text-left disabled:opacity-55',
                    )}
                >
                    <span className="min-w-0 truncate">
                        {selected ? (
                            <>
                                {selected.display}
                                <span className="ml-1.5 text-xs text-muted">
                                    {t('props.pits', {
                                        n: selected.maxPitSlot,
                                    })}
                                </span>
                            </>
                        ) : null}
                    </span>
                    <ChevronsUpDown
                        className="size-4 flex-none text-muted"
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
                        placeholder={t('props.searchCircuit')}
                        aria-label={t('props.circuit')}
                    />
                    <CommandList id={listId}>
                        <CommandEmpty>{t('props.noResults')}</CommandEmpty>
                        {tracks.map((track) => (
                            <CommandItem
                                key={track.id}
                                value={String(track.id)}
                                keywords={[track.display]}
                                onSelect={() => {
                                    onChange(track.id);
                                    setOpen(false);
                                }}
                            >
                                <Check
                                    className={cn(
                                        'size-4',
                                        track.id === value
                                            ? 'text-primary-ink'
                                            : 'invisible',
                                    )}
                                    aria-hidden="true"
                                />
                                <span className="min-w-0 flex-1">
                                    {track.display}
                                </span>
                                <span className="text-xs text-muted">
                                    {t('props.pits', { n: track.maxPitSlot })}
                                </span>
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
