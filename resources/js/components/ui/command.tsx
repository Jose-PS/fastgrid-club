import { Command as CommandPrimitive } from 'cmdk';
import { Search } from 'lucide-react';
import * as React from 'react';
import { cn } from '@/lib/utils';

function Command({
    className,
    ...props
}: React.ComponentProps<typeof CommandPrimitive>) {
    return (
        <CommandPrimitive
            data-slot="command"
            className={cn(
                'flex h-full w-full flex-col overflow-hidden bg-surface text-fg',
                className,
            )}
            {...props}
        />
    );
}

function CommandInput({
    className,
    ...props
}: React.ComponentProps<typeof CommandPrimitive.Input>) {
    return (
        <div
            data-slot="command-input-wrapper"
            className="flex min-h-11 items-center gap-2 border-b border-border px-3"
        >
            <Search
                className="size-4 flex-none text-muted"
                aria-hidden="true"
            />
            <CommandPrimitive.Input
                data-slot="command-input"
                className={cn(
                    'min-h-11 w-full bg-transparent text-fg outline-hidden placeholder:text-muted disabled:opacity-55',
                    className,
                )}
                {...props}
            />
        </div>
    );
}

function CommandList({
    className,
    ...props
}: React.ComponentProps<typeof CommandPrimitive.List>) {
    return (
        <CommandPrimitive.List
            data-slot="command-list"
            className={cn(
                'max-h-64 overflow-x-hidden overflow-y-auto overscroll-contain p-1',
                className,
            )}
            {...props}
        />
    );
}

function CommandEmpty({
    className,
    ...props
}: React.ComponentProps<typeof CommandPrimitive.Empty>) {
    return (
        <CommandPrimitive.Empty
            data-slot="command-empty"
            className={cn(
                'px-3 py-6 text-center text-sm text-muted',
                className,
            )}
            {...props}
        />
    );
}

function CommandItem({
    className,
    ...props
}: React.ComponentProps<typeof CommandPrimitive.Item>) {
    return (
        <CommandPrimitive.Item
            data-slot="command-item"
            className={cn(
                'relative flex min-h-11 cursor-pointer items-center gap-2 rounded-xs px-2.5 py-2 text-sm outline-hidden select-none data-[disabled=true]:pointer-events-none data-[disabled=true]:opacity-55 data-[selected=true]:bg-surface-2 [&_svg]:pointer-events-none [&_svg]:shrink-0',
                className,
            )}
            {...props}
        />
    );
}

export { Command, CommandEmpty, CommandInput, CommandItem, CommandList };
