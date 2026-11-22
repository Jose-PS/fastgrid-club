import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import {
    Pagination,
    PaginationContent,
    PaginationItem,
    PaginationLink,
} from '@/components/ui/pagination';
import { useT } from '@/hooks/use-t';

export type HistoryPage<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_url: string | null;
    next_url: string | null;
};

export function HistoryPagination<T>({
    page,
    only,
}: {
    page: HistoryPage<T>;
    only: string;
}) {
    const t = useT();

    if (page.last_page <= 1) {
        return null;
    }

    const linkProps = (href: string) => ({
        href,
        only: [only],
        preserveScroll: true,
        preserveState: true,
    });

    return (
        <Pagination aria-label={t('admin.servers.pagination')}>
            <PaginationContent className="flex-wrap justify-center">
                <PaginationItem>
                    {page.prev_url === null ? (
                        <PaginationLink
                            size="default"
                            aria-disabled="true"
                            className="pointer-events-none gap-1 opacity-50"
                        >
                            <ChevronLeft aria-hidden="true" />
                            <span>{t('admin.servers.prev')}</span>
                        </PaginationLink>
                    ) : (
                        <PaginationLink asChild size="default">
                            <Link
                                {...linkProps(page.prev_url)}
                                className="gap-1"
                            >
                                <ChevronLeft aria-hidden="true" />
                                <span>{t('admin.servers.prev')}</span>
                            </Link>
                        </PaginationLink>
                    )}
                </PaginationItem>
                <PaginationItem>
                    <span className="px-3 text-sm text-muted">
                        {t('admin.servers.pageOf', {
                            page: page.current_page,
                            pages: page.last_page,
                        })}
                    </span>
                </PaginationItem>
                <PaginationItem>
                    {page.next_url === null ? (
                        <PaginationLink
                            size="default"
                            aria-disabled="true"
                            className="pointer-events-none gap-1 opacity-50"
                        >
                            <span>{t('admin.servers.next')}</span>
                            <ChevronRight aria-hidden="true" />
                        </PaginationLink>
                    ) : (
                        <PaginationLink asChild size="default">
                            <Link
                                {...linkProps(page.next_url)}
                                className="gap-1"
                            >
                                <span>{t('admin.servers.next')}</span>
                                <ChevronRight aria-hidden="true" />
                            </Link>
                        </PaginationLink>
                    )}
                </PaginationItem>
            </PaginationContent>
        </Pagination>
    );
}
