import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { buildTabUrl, readTabFromUrl, resolveTabId } from '@/lib/tab-query';
import { cn } from '@/lib/utils';

export type TabItem = {
    id: string;
    label: string;
    status?: ReactNode;
    badge?: number;
    dot?: boolean;
    indicatorLabel?: string;
    panel: ReactNode;
};

export type TabsProps = {
    label: string;
    items: TabItem[];
    activeId?: string;
    onChange?: (id: string) => void;
    defaultId?: string;
    queryKey?: string;
};

function TabIndicator({ item }: { item: TabItem }) {
    const showBadge = item.badge !== undefined && item.badge > 0;

    if (!showBadge && !item.dot) {
        return null;
    }

    return (
        <>
            {showBadge ? (
                <span
                    aria-hidden="true"
                    className="inline-flex min-w-5 items-center justify-center rounded-full bg-err px-1.5 mono text-xs leading-5 font-bold text-on-primary"
                >
                    {item.badge}
                </span>
            ) : (
                <span
                    aria-hidden="true"
                    className="size-2 shrink-0 rounded-full bg-warn"
                />
            )}
            <span className="sr-only">
                {showBadge
                    ? `${item.badge} ${item.indicatorLabel ?? ''}`.trim()
                    : item.indicatorLabel}
            </span>
        </>
    );
}

export function Tabs({
    label,
    items,
    activeId,
    onChange,
    defaultId,
    queryKey,
}: TabsProps) {
    const pageUrl = usePage().url;
    const ids = items.map((item) => item.id);
    const [internalId, setInternalId] = useState(() =>
        resolveTabId(
            ids,
            queryKey ? readTabFromUrl(pageUrl, queryKey) : null,
            defaultId,
        ),
    );
    const current = resolveTabId(ids, activeId ?? internalId, defaultId);
    const refs = useRef<Record<string, HTMLButtonElement | null>>({});

    useEffect(() => {
        refs.current[current]?.scrollIntoView({
            block: 'nearest',
            inline: 'nearest',
        });
    }, [current]);

    const select = (id: string): void => {
        if (id === current) {
            return;
        }

        if (activeId === undefined) {
            setInternalId(id);
        }

        onChange?.(id);

        if (queryKey) {
            router.replace({
                url: buildTabUrl(
                    window.location.pathname +
                        window.location.search +
                        window.location.hash,
                    queryKey,
                    id,
                ),
                preserveScroll: true,
                preserveState: true,
            });
        }
    };

    const move = (index: number): void => {
        const target = items[(index + items.length) % items.length];

        select(target.id);
        refs.current[target.id]?.focus();
    };

    const onKeyDown = (
        event: KeyboardEvent<HTMLButtonElement>,
        index: number,
    ): void => {
        const keys: Record<string, number> = {
            ArrowRight: index + 1,
            ArrowLeft: index - 1,
            Home: 0,
            End: items.length - 1,
        };

        if (event.key in keys) {
            event.preventDefault();
            move(keys[event.key]);
        }
    };

    return (
        <div className="grid gap-5">
            <div
                role="tablist"
                aria-label={label}
                className="flex [scrollbar-width:none] overflow-x-auto overscroll-x-contain border-b border-border [&::-webkit-scrollbar]:hidden"
            >
                {items.map((item, index) => {
                    const active = item.id === current;

                    return (
                        <button
                            key={item.id}
                            ref={(node) => {
                                refs.current[item.id] = node;
                            }}
                            id={`${item.id}-tab`}
                            type="button"
                            role="tab"
                            aria-selected={active}
                            aria-controls={`${item.id}-panel`}
                            tabIndex={active ? 0 : -1}
                            onClick={() => select(item.id)}
                            onKeyDown={(event) => onKeyDown(event, index)}
                            className={cn(
                                '-mb-px grid min-h-14 min-w-fit flex-1 basis-0 content-center justify-items-center gap-0.5 border-b-2 px-4 py-2 font-display text-base font-bold whitespace-nowrap transition-colors duration-150 ease-out',
                                active
                                    ? 'border-primary text-fg'
                                    : 'border-transparent text-muted hover:text-fg',
                            )}
                        >
                            <span className="flex items-center gap-1.5">
                                <span>{item.label}</span>
                                <TabIndicator item={item} />
                            </span>
                            <span className="flex min-h-4 max-w-full items-center gap-1 mono text-xs font-medium text-muted">
                                {item.status}
                            </span>
                        </button>
                    );
                })}
            </div>
            {items.map((item) => (
                <div
                    key={item.id}
                    id={`${item.id}-panel`}
                    role="tabpanel"
                    aria-labelledby={`${item.id}-tab`}
                    hidden={item.id !== current}
                >
                    {item.panel}
                </div>
            ))}
        </div>
    );
}
