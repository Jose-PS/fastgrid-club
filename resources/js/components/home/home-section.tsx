import type { ReactNode } from 'react';

export function HomeSection({
    id,
    title,
    meta,
    action,
    children,
}: {
    id: string;
    title: string;
    meta?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section aria-labelledby={id} className="grid gap-5">
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                <h2
                    id={id}
                    className="font-display text-[28px] leading-tight font-bold tracking-[-0.02em] text-balance"
                >
                    {title}
                </h2>
                {meta ? (
                    <span className="text-sm text-muted">{meta}</span>
                ) : null}
                {action}
            </div>
            {children}
        </section>
    );
}

export function EmptyPanel({ message }: { message: string }) {
    return (
        <p className="rounded-md border border-dashed border-border-strong px-4 py-6 text-sm text-muted">
            {message}
        </p>
    );
}
