import { describe, expect, it } from 'vitest';
import {
    centsToEurosInput,
    clampPercent,
    eurosToCents,
    formatEuros,
} from './donation';

describe('formatEuros', () => {
    it('formats Spanish amounts with the symbol after', () => {
        expect(formatEuros(123450, 'es').replace(/\s/g, ' ')).toBe(
            '1.234,50 €',
        );
        expect(formatEuros(2200, 'es').replace(/\s/g, ' ')).toBe('22 €');
    });

    it('formats English amounts with the symbol before', () => {
        expect(formatEuros(123450, 'en')).toBe('€1,234.50');
        expect(formatEuros(3800, 'en')).toBe('€38');
    });
});

describe('eurosToCents', () => {
    it('converts whole and decimal euros with comma or dot', () => {
        expect(eurosToCents('38')).toBe(3800);
        expect(eurosToCents('12,5')).toBe(1250);
        expect(eurosToCents('12.05')).toBe(1205);
        expect(eurosToCents(' 0,99 ')).toBe(99);
    });

    it('rejects invalid input', () => {
        expect(eurosToCents('')).toBeNull();
        expect(eurosToCents('-1')).toBeNull();
        expect(eurosToCents('1,234')).toBeNull();
        expect(eurosToCents('abc')).toBeNull();
    });
});

describe('centsToEurosInput', () => {
    it('writes whole euros without decimals and others with two', () => {
        expect(centsToEurosInput(3800)).toBe('38');
        expect(centsToEurosInput(1205)).toBe('12,05');
        expect(centsToEurosInput(1250)).toBe('12,50');
    });
});

describe('clampPercent', () => {
    it('limits the value between 0 and 100', () => {
        expect(clampPercent(140)).toBe(100);
        expect(clampPercent(-3)).toBe(0);
        expect(clampPercent(45)).toBe(45);
    });
});
