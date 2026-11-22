import { describe, expect, it } from 'vitest';
import { normalizeSearch, searchScore } from './search';

describe('normalizeSearch', () => {
    it('removes accents and case', () => {
        expect(normalizeSearch('  Nürburgring ÁÉ ')).toBe('nurburgring ae');
    });
});

describe('searchScore', () => {
    it('matches everything on an empty query', () => {
        expect(searchScore('Monza', '')).toBe(1);
    });

    it('ignores accents and case', () => {
        expect(searchScore('Nürburgring GP', 'nurb')).toBe(1);
    });

    it('ranks prefixes above inner matches', () => {
        expect(searchScore('Ferrari 296', 'fer')).toBeGreaterThan(
            searchScore('Scuderia Ferrari', 'fer'),
        );
    });

    it('rejects text without the query', () => {
        expect(searchScore('Monza', 'spa')).toBe(0);
    });
});
