import { describe, expect, it } from 'vitest';
import { splitCarName } from './car-name';

describe('splitCarName', () => {
    it('separates the variant after the separator', () => {
        expect(splitCarName('Ferrari 296 GT3 - Standard')).toEqual({
            model: 'Ferrari 296 GT3',
            variant: 'Standard',
        });
    });

    it('splits on the last separator', () => {
        expect(splitCarName('Porsche 911 - GT3 R - Standard')).toEqual({
            model: 'Porsche 911 - GT3 R',
            variant: 'Standard',
        });
    });

    it('has no variant without separator', () => {
        expect(splitCarName('Mazda MX-5')).toEqual({
            model: 'Mazda MX-5',
            variant: null,
        });
    });

    it('does not split on hyphens without spaces', () => {
        expect(splitCarName('BMW M4-GT3').variant).toBeNull();
    });

    it('ignores an empty variant or model', () => {
        expect(splitCarName('Audi R8 - ').variant).toBeNull();
        expect(splitCarName(' - Standard').variant).toBeNull();
    });
});
