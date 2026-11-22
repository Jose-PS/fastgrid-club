import { SlidersHorizontal } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { useT } from '@/hooks/use-t';
import { parseBound } from '@/lib/catalog-filters';
import { cn } from '@/lib/utils';
import { inputClass, secondaryButtonClass } from '../account-styles';

const legendClass = 'mb-2 text-sm font-semibold';

export function ChipGroup({
    legend,
    values,
    selected,
    label,
    onToggle,
    scrollable = false,
}: {
    legend: string;
    values: string[];
    selected: string[];
    label: (value: string) => string;
    onToggle: (value: string) => void;
    scrollable?: boolean;
}) {
    if (values.length === 0) {
        return null;
    }

    return (
        <fieldset className="min-w-0">
            <legend className={legendClass}>
                {legend}
                {selected.length > 0 ? (
                    <span className="ml-1.5 mono text-xs font-normal text-muted">
                        ({selected.length})
                    </span>
                ) : null}
            </legend>
            <div
                {...(scrollable
                    ? { role: 'region', tabIndex: 0, 'aria-label': legend }
                    : {})}
                className={cn(
                    'flex flex-wrap gap-2',
                    scrollable && 'max-h-52 overflow-y-auto pr-1',
                )}
            >
                {values.map((value) => (
                    <label key={value} className="relative inline-flex">
                        <input
                            type="checkbox"
                            className="peer sr-only"
                            checked={selected.includes(value)}
                            onChange={() => onToggle(value)}
                        />
                        <span className="inline-flex min-h-11 cursor-pointer items-center rounded-sm border border-border-strong px-3 text-sm transition-colors duration-150 ease-out peer-checked:border-primary peer-checked:bg-tint peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-info hover:bg-surface-2">
                            {label(value)}
                        </span>
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

export function RangeFields({
    idPrefix,
    legend,
    minValue,
    maxValue,
    minPlaceholder,
    maxPlaceholder,
    step,
    onMinChange,
    onMaxChange,
}: {
    idPrefix: string;
    legend: string;
    minValue: string;
    maxValue: string;
    minPlaceholder: string;
    maxPlaceholder: string;
    step: string;
    onMinChange: (value: string) => void;
    onMaxChange: (value: string) => void;
}) {
    const t = useT();

    return (
        <fieldset className="min-w-0">
            <legend className={legendClass}>{legend}</legend>
            <div className="grid grid-cols-2 gap-2">
                <div className="grid gap-1">
                    <label
                        htmlFor={`${idPrefix}-min`}
                        className="text-xs text-muted"
                    >
                        {t('props.min')}
                    </label>
                    <input
                        id={`${idPrefix}-min`}
                        type="number"
                        inputMode="decimal"
                        min="0"
                        step={step}
                        value={minValue}
                        placeholder={minPlaceholder}
                        onChange={(event) => onMinChange(event.target.value)}
                        className={inputClass}
                    />
                </div>
                <div className="grid gap-1">
                    <label
                        htmlFor={`${idPrefix}-max`}
                        className="text-xs text-muted"
                    >
                        {t('props.max')}
                    </label>
                    <input
                        id={`${idPrefix}-max`}
                        type="number"
                        inputMode="decimal"
                        min="0"
                        step={step}
                        value={maxValue}
                        placeholder={maxPlaceholder}
                        onChange={(event) => onMaxChange(event.target.value)}
                        className={inputClass}
                    />
                </div>
            </div>
        </fieldset>
    );
}

export function NumberField({
    id,
    label,
    value,
    placeholder,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    placeholder: string;
    onChange: (value: string) => void;
}) {
    const invalid = value.trim() !== '' && parseBound(value) === null;

    return (
        <div className="grid gap-1.5">
            <label htmlFor={id} className="text-sm font-semibold">
                {label}
            </label>
            <input
                id={id}
                type="number"
                inputMode="numeric"
                min="0"
                step="1"
                value={value}
                placeholder={placeholder}
                aria-invalid={invalid ? true : undefined}
                onChange={(event) => onChange(event.target.value)}
                className={inputClass}
            />
        </div>
    );
}

export function SearchField({
    id,
    label,
    value,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid gap-1.5">
            <label htmlFor={id} className="text-sm font-semibold">
                {label}
            </label>
            <input
                id={id}
                type="search"
                autoComplete="off"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className={inputClass}
            />
        </div>
    );
}

export function SortField({
    id,
    value,
    options,
    onChange,
}: {
    id: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    const t = useT();

    return (
        <div className="grid gap-1.5">
            <label htmlFor={id} className="text-sm font-semibold">
                {t('props.sort')}
            </label>
            <select
                id={id}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className={inputClass}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

export function FilterPanel({
    id,
    canClear,
    onClear,
    children,
}: {
    id: string;
    canClear: boolean;
    onClear: () => void;
    children: ReactNode;
}) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const panelId = `${id}-panel`;

    return (
        <div className="grid content-start gap-3">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen(!open)}
                className={cn(
                    secondaryButtonClass,
                    'justify-self-start lg:hidden',
                )}
            >
                <SlidersHorizontal className="size-[18px]" aria-hidden="true" />
                {open ? t('props.hideFilters') : t('props.showFilters')}
            </button>
            <div
                id={panelId}
                className={cn(
                    'gap-5 rounded-md border border-border bg-surface p-4',
                    open ? 'grid' : 'hidden lg:grid',
                )}
            >
                <h3 className="font-display text-base font-bold">
                    {t('props.filters')}
                </h3>
                {children}
                <button
                    type="button"
                    disabled={!canClear}
                    onClick={onClear}
                    className={cn(secondaryButtonClass, 'justify-self-start')}
                >
                    {t('props.clearFilters')}
                </button>
            </div>
        </div>
    );
}
