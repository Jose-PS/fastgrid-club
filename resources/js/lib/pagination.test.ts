import { describe, expect, it } from 'vitest';
import { CARS_PER_PAGE, pageNumbers, paginate } from './pagination';

const items = Array.from({ length: 30 }, (_, index) => index + 1);

describe('paginate', () => {
    it('uses twelve cars per page', () => {
        expect(CARS_PER_PAGE).toBe(12);
    });

    it('returns the first page slice and its range', () => {
        const result = paginate(items, 1, 12);

        expect(result.items).toEqual(items.slice(0, 12));
        expect(result).toMatchObject({
            page: 1,
            totalPages: 3,
            from: 1,
            to: 12,
            total: 30,
        });
    });

    it('returns a shorter last page', () => {
        const result = paginate(items, 3, 12);

        expect(result.items).toEqual([25, 26, 27, 28, 29, 30]);
        expect(result).toMatchObject({ page: 3, from: 25, to: 30 });
    });

    it('clamps pages out of range', () => {
        expect(paginate(items, 9, 12).page).toBe(3);
        expect(paginate(items, 0, 12).page).toBe(1);
        expect(paginate(items, -4, 12).page).toBe(1);
        expect(paginate(items, Number.NaN, 12).page).toBe(1);
    });

    it('handles an empty list', () => {
        expect(paginate([], 1, 12)).toEqual({
            items: [],
            page: 1,
            totalPages: 1,
            from: 0,
            to: 0,
            total: 0,
        });
    });

    it('fits an exact multiple in whole pages', () => {
        expect(paginate(items.slice(0, 24), 2, 12)).toMatchObject({
            totalPages: 2,
            from: 13,
            to: 24,
        });
    });
});

describe('pageNumbers', () => {
    it('lists every page when there are few', () => {
        expect(pageNumbers(1, 1)).toEqual([1]);
        expect(pageNumbers(3, 7)).toEqual([1, 2, 3, 4, 5, 6, 7]);
    });

    it('collapses the end with an ellipsis near the start', () => {
        expect(pageNumbers(1, 10)).toEqual([1, 2, 3, 4, 'ellipsis-end', 10]);
        expect(pageNumbers(3, 10)).toEqual([1, 2, 3, 4, 'ellipsis-end', 10]);
    });

    it('collapses both sides in the middle', () => {
        expect(pageNumbers(5, 10)).toEqual([
            1,
            'ellipsis-start',
            4,
            5,
            6,
            'ellipsis-end',
            10,
        ]);
    });

    it('collapses the start near the end', () => {
        expect(pageNumbers(10, 10)).toEqual([1, 'ellipsis-start', 7, 8, 9, 10]);
        expect(pageNumbers(8, 10)).toEqual([1, 'ellipsis-start', 7, 8, 9, 10]);
    });

    it('does not insert an ellipsis for a gap of one page', () => {
        expect(pageNumbers(4, 8)).toEqual([1, 2, 3, 4, 5, 'ellipsis-end', 8]);
    });
});
