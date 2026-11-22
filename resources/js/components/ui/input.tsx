import * as React from 'react';
import { cn } from '@/lib/utils';

function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
    return (
        <input
            type={type}
            data-slot="input"
            className={cn(
                'min-h-11 w-full min-w-0 rounded-sm border border-border-strong bg-surface px-3 text-fg transition-[color,box-shadow] outline-none placeholder:text-muted disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-err',
                className,
            )}
            {...props}
        />
    );
}

export { Input };
