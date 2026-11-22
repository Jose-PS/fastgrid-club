import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useT } from '@/hooks/use-t';
import { showToast } from '@/lib/toast';
import { cn } from '@/lib/utils';

export function ProposableSwitch({
    url,
    checked,
    disabled,
    label,
}: {
    url: string;
    checked: boolean;
    disabled: boolean;
    label: string;
}) {
    const t = useT();
    const [processing, setProcessing] = useState(false);

    const toggle = (): void => {
        router.put(
            url,
            { proposable: !checked },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () =>
                    showToast({
                        type: 'success',
                        message: t('admin.catalog.saved'),
                    }),
                onError: (errors) =>
                    showToast({
                        type: 'error',
                        message: Object.values(errors)[0] ?? '',
                    }),
            },
        );
    };

    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled || processing}
            onClick={toggle}
            className="inline-flex min-h-11 min-w-11 items-center justify-center disabled:opacity-55"
        >
            <span
                aria-hidden="true"
                className={cn(
                    'relative inline-block h-6 w-11 rounded-full border transition-colors duration-150 ease-out',
                    checked
                        ? 'border-primary bg-primary'
                        : 'border-border-strong bg-surface-2',
                )}
            >
                <span
                    className={cn(
                        'absolute top-0.5 size-4.5 rounded-full bg-fg transition-[left] duration-150 ease-out',
                        checked ? 'left-5.5' : 'left-0.5',
                    )}
                />
            </span>
        </button>
    );
}
