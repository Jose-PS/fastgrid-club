import { Link, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationLink,
} from '@/components/ui/pagination';
import { useT } from '@/hooks/use-t';
import { homeStatsUrl } from '@/lib/home-stats';
import type { StatsPagination } from '@/lib/home-stats';
import { pageNumbers } from '@/lib/pagination';

export function StatsPagination({
    prefix,
    only,
    pagination,
}: {
    prefix: 'lb' | 'res';
    only: string;
    pagination: StatsPagination;
}) {
    const t = useT();
    const { url } = usePage();
    const { currentPage, lastPage, pageName } = pagination;

    if (lastPage <= 1) {
        return null;
    }

    const linkProps = (page: number) => ({
        href: homeStatsUrl(url, { [pageName]: page === 1 ? null : page }),
        only: [only],
        preserveScroll: true,
        preserveState: true,
    });

    return (
        <div className="grid gap-2">
            <Pagination aria-label={t(`${prefix}.pagination`)}>
                <PaginationContent className="flex-wrap justify-center">
                    <PaginationItem>
                        {currentPage > 1 ? (
                            <PaginationLink asChild size="default">
                                <Link
                                    {...linkProps(currentPage - 1)}
                                    aria-label={t(`${prefix}.prev`)}
                                    className="gap-1"
                                >
                                    <ChevronLeft aria-hidden="true" />
                                    <span className="hidden sm:inline">
                                        {t(`${prefix}.prev`)}
                                    </span>
                                </Link>
                            </PaginationLink>
                        ) : (
                            <PaginationLink
                                size="default"
                                aria-disabled="true"
                                className="pointer-events-none gap-1 opacity-55"
                            >
                                <ChevronLeft aria-hidden="true" />
                                <span className="hidden sm:inline">
                                    {t(`${prefix}.prev`)}
                                </span>
                            </PaginationLink>
                        )}
                    </PaginationItem>
                    {pageNumbers(currentPage, lastPage).map((number) =>
                        typeof number === 'string' ? (
                            <PaginationItem key={number}>
                                <PaginationEllipsis />
                            </PaginationItem>
                        ) : (
                            <PaginationItem key={number}>
                                <PaginationLink
                                    asChild
                                    isActive={number === currentPage}
                                >
                                    <Link
                                        {...linkProps(number)}
                                        aria-label={t(`${prefix}.page`, {
                                            n: number,
                                        })}
                                    >
                                        {number}
                                    </Link>
                                </PaginationLink>
                            </PaginationItem>
                        ),
                    )}
                    <PaginationItem>
                        {currentPage < lastPage ? (
                            <PaginationLink asChild size="default">
                                <Link
                                    {...linkProps(currentPage + 1)}
                                    aria-label={t(`${prefix}.next`)}
                                    className="gap-1"
                                >
                                    <span className="hidden sm:inline">
                                        {t(`${prefix}.next`)}
                                    </span>
                                    <ChevronRight aria-hidden="true" />
                                </Link>
                            </PaginationLink>
                        ) : (
                            <PaginationLink
                                size="default"
                                aria-disabled="true"
                                className="pointer-events-none gap-1 opacity-55"
                            >
                                <span className="hidden sm:inline">
                                    {t(`${prefix}.next`)}
                                </span>
                                <ChevronRight aria-hidden="true" />
                            </PaginationLink>
                        )}
                    </PaginationItem>
                </PaginationContent>
            </Pagination>
            <p className="text-center text-sm text-muted" aria-live="polite">
                {t(`${prefix}.pageOf`, { page: currentPage, last: lastPage })}
            </p>
        </div>
    );
}
