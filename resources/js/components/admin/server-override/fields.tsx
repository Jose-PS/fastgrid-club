import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { fieldErrorClass } from '@/components/account-styles';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useT } from '@/hooks/use-t';
import type { SessionForm } from '@/lib/server-override-form';
import { cn } from '@/lib/utils';

export const labelClass = 'text-sm font-semibold';
export const hintClass = 'text-[13px] text-muted';
export const checkboxClass = 'size-5 accent-primary';

export function Field({
    label,
    htmlFor,
    hint,
    error,
    children,
}: {
    label: string;
    htmlFor?: string;
    hint?: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-1.5">
            <label htmlFor={htmlFor} className={labelClass}>
                {label}
            </label>
            {children}
            {hint ? <p className={hintClass}>{hint}</p> : null}
            {error ? (
                <p role="alert" className={fieldErrorClass}>
                    {error}
                </p>
            ) : null}
        </div>
    );
}

export function Check({
    id,
    label,
    checked,
    disabled,
    onChange,
}: {
    id: string;
    label: string;
    checked: boolean;
    disabled?: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label
            htmlFor={id}
            className="flex min-h-11 items-center gap-2.5 text-sm"
        >
            <input
                id={id}
                type="checkbox"
                className={checkboxClass}
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
            />
            {label}
        </label>
    );
}

export function Choice({
    id,
    label,
    value,
    values,
    labelFor,
    onChange,
    error,
}: {
    id: string;
    label: string;
    value: string;
    values: string[];
    labelFor: (value: string) => string;
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <Field label={label} htmlFor={id} error={error}>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger id={id} aria-label={label}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {values.map((item) => (
                        <SelectItem key={item} value={item}>
                            {labelFor(item)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );
}

export function NumberField({
    id,
    label,
    value,
    onChange,
    error,
    min,
    max,
    step,
    hint,
    disabled,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    min?: number;
    max?: number;
    step?: number;
    hint?: string;
    disabled?: boolean;
}) {
    return (
        <Field label={label} htmlFor={id} error={error} hint={hint}>
            <Input
                id={id}
                type="number"
                inputMode="decimal"
                min={min}
                max={max}
                step={step}
                value={value}
                disabled={disabled}
                aria-invalid={error ? true : undefined}
                onChange={(event) => onChange(event.target.value)}
            />
        </Field>
    );
}

export function PasswordField({
    id,
    label,
    value,
    cleared,
    hint,
    error,
    onChange,
    onClear,
}: {
    id: string;
    label: string;
    value: string;
    cleared: boolean;
    hint?: string;
    error?: string;
    onChange: (value: string) => void;
    onClear: (cleared: boolean) => void;
}) {
    const t = useT();
    const [visible, setVisible] = useState(false);

    return (
        <Field label={label} htmlFor={id} hint={hint} error={error}>
            <div className="flex gap-2">
                <Input
                    id={id}
                    type={visible ? 'text' : 'password'}
                    autoComplete="new-password"
                    maxLength={64}
                    value={cleared ? '' : value}
                    disabled={cleared}
                    aria-invalid={error ? true : undefined}
                    onChange={(event) => onChange(event.target.value)}
                />
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={t(
                        visible
                            ? 'admin.override.hidePassword'
                            : 'admin.override.showPassword',
                    )}
                    aria-pressed={visible}
                    onClick={() => setVisible(!visible)}
                >
                    {visible ? (
                        <EyeOff aria-hidden="true" />
                    ) : (
                        <Eye aria-hidden="true" />
                    )}
                </Button>
            </div>
            <Check
                id={`${id}-clear`}
                label={t('admin.override.clearPassword')}
                checked={cleared}
                onChange={onClear}
            />
        </Field>
    );
}

export function SessionFields({
    id,
    value,
    onChange,
    errors,
    lengthKey,
    compact,
}: {
    id: string;
    value: SessionForm;
    onChange: (patch: Partial<SessionForm>) => void;
    errors: Record<string, string | undefined>;
    lengthKey: string;
    compact?: boolean;
}) {
    const t = useT();

    return (
        <div
            className={cn(
                'grid gap-4 sm:grid-cols-2',
                !compact && 'lg:grid-cols-3',
            )}
        >
            <NumberField
                id={`${id}-length`}
                label={t('admin.override.lengthMin')}
                min={0}
                step={1}
                value={value.length_min}
                error={errors[lengthKey]}
                onChange={(length_min) => onChange({ length_min })}
            />
            <Field label={t('admin.override.startTime')} htmlFor={`${id}-time`}>
                <Input
                    id={`${id}-time`}
                    type="time"
                    value={value.start_time}
                    onChange={(event) =>
                        onChange({ start_time: event.target.value })
                    }
                />
            </Field>
            <NumberField
                id={`${id}-multiplier`}
                label={t('admin.override.multiplier')}
                min={1}
                max={60}
                step={1}
                value={value.time_multiplier}
                onChange={(time_multiplier) => onChange({ time_multiplier })}
            />
            <NumberField
                id={`${id}-box`}
                label={t('admin.override.maxWaitToBox')}
                min={0}
                max={3600}
                step={1}
                value={value.max_wait_to_box}
                onChange={(max_wait_to_box) => onChange({ max_wait_to_box })}
            />
            <NumberField
                id={`${id}-overtime`}
                label={t('admin.override.overtime')}
                min={0}
                max={3600}
                step={1}
                value={value.overtime_waiting_next_session}
                onChange={(overtime_waiting_next_session) =>
                    onChange({ overtime_waiting_next_session })
                }
            />
        </div>
    );
}

export function Fact({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-xs tracking-wide text-muted uppercase">
                {label}
            </dt>
            <dd className="text-sm font-medium">{value}</dd>
        </div>
    );
}
