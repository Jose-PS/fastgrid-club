import { describe, expect, it } from 'vitest';
import { homeStatsUrl } from './home-stats';

describe('homeStatsUrl', () => {
    it('sets a parameter and keeps the others', () => {
        expect(homeStatsUrl('/?res_page=2', { lb_page: 3 })).toBe(
            '/?res_page=2&lb_page=3',
        );
    });

    it('removes parameters set to null', () => {
        expect(
            homeStatsUrl('/?lb_track=1&lb_page=4', {
                lb_track: 2,
                lb_page: null,
            }),
        ).toBe('/?lb_track=2');
    });

    it('returns the bare path when nothing is left', () => {
        expect(homeStatsUrl('/?lb_page=2', { lb_page: null })).toBe('/');
    });
});
