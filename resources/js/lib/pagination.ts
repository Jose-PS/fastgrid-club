export const CARS_PER_PAGE = 12;

export type Page<T> = {
    items: T[];
    page: number;
    totalPages: number;
    from: number;
    to: number;
    total: number;
};

export type PageNumber = number | 'ellipsis-start' | 'ellipsis-end';

export function paginate<T>(items: T[], page: number, size: number): Page<T> {
    const total = items.length;
    const totalPages = Math.max(1, Math.ceil(total / size));
    const current = Math.min(Math.max(1, Math.floor(page) || 1), totalPages);
    const start = (current - 1) * size;
    const slice = items.slice(start, start + size);

    return {
        items: slice,
        page: current,
        totalPages,
        from: total === 0 ? 0 : start + 1,
        to: start + slice.length,
        total,
    };
}

export function pageNumbers(current: number, totalPages: number): PageNumber[] {
    if (totalPages <= 7) {
        return Array.from({ length: totalPages }, (_, index) => index + 1);
    }

    const numbers = new Set<number>([
        1,
        totalPages,
        current - 1,
        current,
        current + 1,
    ]);

    if (current <= 3) {
        [2, 3, 4].forEach((number) => numbers.add(number));
    }

    if (current >= totalPages - 2) {
        [totalPages - 3, totalPages - 2, totalPages - 1].forEach((number) =>
            numbers.add(number),
        );
    }

    const sorted = [...numbers]
        .filter((number) => number >= 1 && number <= totalPages)
        .sort((a, b) => a - b);
    const result: PageNumber[] = [];

    sorted.forEach((number, index) => {
        const previous = sorted[index - 1];

        if (previous !== undefined && number - previous === 2) {
            result.push(previous + 1);
        } else if (previous !== undefined && number - previous > 2) {
            result.push(previous === 1 ? 'ellipsis-start' : 'ellipsis-end');
        }

        result.push(number);
    });

    return result;
}
