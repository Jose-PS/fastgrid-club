import { describe, expect, it } from 'vitest';
import { hasValidCarCount, toggleCar, votePercentage } from './proposal-form';

describe('toggleCar', () => {
    it('adds a car that is not selected', () => {
        expect(toggleCar([1], 2)).toEqual([1, 2]);
    });

    it('removes a car that is already selected', () => {
        expect(toggleCar([1, 2], 1)).toEqual([2]);
    });

    it('adds a fourth car', () => {
        expect(toggleCar([1, 2, 3], 4)).toEqual([1, 2, 3, 4]);
    });

    it('still removes from a large selection', () => {
        expect(toggleCar([1, 2, 3], 2)).toEqual([1, 3]);
    });
});

describe('hasValidCarCount', () => {
    it('rejects an empty selection', () => {
        expect(hasValidCarCount([])).toBe(false);
    });

    it('accepts one or more cars', () => {
        expect(hasValidCarCount([1])).toBe(true);
        expect(hasValidCarCount([1, 2, 3])).toBe(true);
        expect(hasValidCarCount([1, 2, 3, 4, 5])).toBe(true);
    });
});

describe('votePercentage', () => {
    it('is relative to the maximum', () => {
        expect(votePercentage(5, 10)).toBe(50);
        expect(votePercentage(10, 10)).toBe(100);
    });

    it('is zero when nobody has votes', () => {
        expect(votePercentage(0, 0)).toBe(0);
    });
});
