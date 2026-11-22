import * as PopoverPrimitive from '@radix-ui/react-popover';
import * as React from 'react';
import { cn } from '@/lib/utils';

const Popover = PopoverPrimitive.Root;

const PopoverTrigger = PopoverPrimitive.Trigger;

const PopoverAnchor = PopoverPrimitive.Anchor;

function PopoverContent({
    className,
    align = 'start',
    sideOffset = 6,
    ...props
}: React.ComponentProps<typeof PopoverPrimitive.Content>) {
    return (
        <PopoverPrimitive.Portal>
            <PopoverPrimitive.Content
                data-slot="popover-content"
                align={align}
                sideOffset={sideOffset}
                className={cn(
                    'z-50 w-(--radix-popover-trigger-width) min-w-64 origin-(--radix-popover-content-transform-origin) overflow-hidden rounded-md border border-border-strong bg-surface text-fg shadow-lg outline-hidden',
                    className,
                )}
                {...props}
            />
        </PopoverPrimitive.Portal>
    );
}

export { Popover, PopoverAnchor, PopoverContent, PopoverTrigger };
