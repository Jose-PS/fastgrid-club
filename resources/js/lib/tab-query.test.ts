import { describe, expect, it } from 'vitest';
import { buildTabUrl, readTabFromUrl, resolveTabId } from './tab-query';

describe('resolveTabId', () => {
    const ids = ['a', 'b', 'c'];

    it('keeps a known candidate', () => {
        expect(resolveTabId(ids, 'b')).toBe('b');
    });

    it('falls back to the given default when the candidate is unknown', () => {
        expect(resolveTabId(ids, 'zzz', 'c')).toBe('c');
    });

    it('falls back to the first tab when nothing valid is given', () => {
        expect(resolveTabId(ids, null)).toBe('a');
        expect(resolveTabId(ids, 'zzz', 'nope')).toBe('a');
    });
});

describe('readTabFromUrl', () => {
    it('reads the parameter ignoring the hash', () => {
        expect(readTabFromUrl('/admin/catalog?x=1&tab=cars#top', 'tab')).toBe(
            'cars',
        );
    });

    it('returns null when absent', () => {
        expect(readTabFromUrl('/admin/catalog', 'tab')).toBeNull();
        expect(readTabFromUrl('/admin/catalog?x=1#tab=cars', 'tab')).toBeNull();
    });
});

describe('buildTabUrl', () => {
    it('adds the parameter keeping path, query and hash', () => {
        expect(buildTabUrl('/admin/catalog?x=1#top', 'tab', 'cars')).toBe(
            '/admin/catalog?x=1&tab=cars#top',
        );
    });

    it('replaces an existing value', () => {
        expect(buildTabUrl('/p?tab=a', 'tab', 'b')).toBe('/p?tab=b');
    });
});
