import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';
import { Toggle as TogglePrimitive } from 'radix-ui';

const toggleVariants = cva(
    "inline-flex items-center justify-center gap-2 rounded-sm text-sm font-medium whitespace-nowrap transition-[color,box-shadow] hover:bg-surface-2 hover:text-fg disabled:pointer-events-none disabled:opacity-50 data-[state=on]:bg-surface-2 data-[state=on]:text-fg data-[state=on]:shadow-[inset_0_0_0_1px_var(--border-strong)] [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
    {
        variants: {
            variant: {
                default: 'bg-transparent',
                outline:
                    'border border-border-strong bg-transparent hover:bg-surface-2',
            },
            size: {
                default: 'min-h-11 min-w-11 px-2',
                sm: 'min-h-9 min-w-9 px-1.5',
                lg: 'min-h-12 min-w-12 px-2.5',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

function Toggle({
    className,
    variant,
    size,
    ...props
}: React.ComponentProps<typeof TogglePrimitive.Root> &
    VariantProps<typeof toggleVariants>) {
    return (
        <TogglePrimitive.Root
            data-slot="toggle"
            className={cn(toggleVariants({ variant, size, className }))}
            {...props}
        />
    );
}

export { Toggle, toggleVariants };
