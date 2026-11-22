import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useT } from '@/hooks/use-t';
import { pageNumbers } from '@/lib/pagination';
import type { Page } from '@/lib/pagination';
import { cn } from '@/lib/utils';

const itemClass =
    'inline-flex min-h-11 min-w-11 items-center justify-center rounded-sm border px-3 text-sm font-semibold transition-colors duration-150 ease-out disabled:opacity-55';

export function Pagination({
    page,
    onChange,
}: {
    page: Page<unknown>;
    onChange: (page: number) => void;
}) {
    const t = useT();

    if (page.totalPages <= 1) {
        return null;
    }

    return (
        <nav
            aria-label={t('props.pagination')}
            className="flex flex-wrap items-center justify-center gap-1.5"
        >
            <button
                type="button"
                disabled={page.page <= 1}
                onClick={() => onChange(page.page - 1)}
                className={cn(
                    itemClass,
                    'gap-1 border-border-strong hover:bg-surface-2',
                )}
            >
                <ChevronLeft className="size-4" aria-hidden="true" />
                <span className="hidden sm:inline">{t('props.prev')}</span>
                <span className="sr-only sm:hidden">{t('props.prev')}</span>
            </button>
            {pageNumbers(page.page, page.totalPages).map((number) =>
                typeof number === 'string' ? (
                    <span
                        key={number}
                        aria-hidden="true"
                        className="grid min-h-11 min-w-6 place-items-center mono text-muted"
                    >
                        …
                    </span>
                ) : (
                    <button
                        key={number}
                        type="button"
                        aria-label={t('props.page', { n: number })}
                        aria-current={number === page.page ? 'page' : undefined}
                        onClick={() => onChange(number)}
                        className={cn(
                            itemClass,
                            number === page.page
                                ? 'border-primary bg-primary text-on-primary'
                                : 'border-border-strong hover:bg-surface-2',
                        )}
                    >
                        {number}
                    </button>
                ),
            )}
            <button
                type="button"
                disabled={page.page >= page.totalPages}
                onClick={() => onChange(page.page + 1)}
                className={cn(
                    itemClass,
                    'gap-1 border-border-strong hover:bg-surface-2',
                )}
            >
                <span className="hidden sm:inline">{t('props.next')}</span>
                <span className="sr-only sm:hidden">{t('props.next')}</span>
                <ChevronRight className="size-4" aria-hidden="true" />
            </button>
        </nav>
    );
}
