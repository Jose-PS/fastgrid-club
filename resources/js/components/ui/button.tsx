import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';
import { Slot } from 'radix-ui';

const buttonVariants = cva(
    "inline-flex shrink-0 items-center justify-center gap-2 rounded-sm text-sm font-medium whitespace-nowrap transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
    {
        variants: {
            variant: {
                default: 'bg-primary text-on-primary hover:opacity-90',
                destructive: 'bg-err text-on-primary hover:opacity-90',
                outline:
                    'border bg-surface shadow-xs hover:bg-surface-2 hover:text-fg',
                secondary: 'bg-surface-2 text-fg hover:bg-surface-2/80',
                ghost: 'hover:bg-surface-2 hover:text-fg',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                default: 'min-h-11 px-4 py-2 has-[>svg]:px-3',
                xs: "h-6 gap-1 rounded-sm px-2 text-xs has-[>svg]:px-1.5 [&_svg:not([class*='size-'])]:size-3",
                sm: 'h-8 gap-1.5 rounded-sm px-3 has-[>svg]:px-2.5',
                lg: 'h-10 rounded-sm px-6 has-[>svg]:px-4',
                icon: 'size-11',
                'icon-xs':
                    "size-6 rounded-sm [&_svg:not([class*='size-'])]:size-3",
                'icon-sm': 'size-9',
                'icon-lg': 'size-10',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

function Button({
    className,
    variant = 'default',
    size = 'default',
    asChild = false,
    ...props
}: React.ComponentProps<'button'> &
    VariantProps<typeof buttonVariants> & {
        asChild?: boolean;
    }) {
    const Comp = asChild ? Slot.Root : 'button';

    return (
        <Comp
            data-slot="button"
            data-variant={variant}
            data-size={size}
            className={cn(buttonVariants({ variant, size, className }))}
            {...props}
        />
    );
}

export { Button, buttonVariants };
