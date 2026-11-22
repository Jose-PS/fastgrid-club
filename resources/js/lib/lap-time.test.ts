import { describe, expect, it } from 'vitest';
import { formatGap, formatLapTime } from './lap-time';

describe('formatLapTime', () => {
    it('formats minutes, seconds and milliseconds', () => {
        expect(formatLapTime(105321)).toBe('1:45.321');
    });

    it('pads seconds and milliseconds', () => {
        expect(formatLapTime(61005)).toBe('1:01.005');
    });

    it('formats laps under one minute', () => {
        expect(formatLapTime(59999)).toBe('0:59.999');
    });

    it('rounds fractional milliseconds', () => {
        expect(formatLapTime(105320.6)).toBe('1:45.321');
    });

    it('returns a placeholder for invalid values', () => {
        expect(formatLapTime(-1)).toBe('--:--.---');
        expect(formatLapTime(Number.NaN)).toBe('--:--.---');
    });
});

describe('formatGap', () => {
    it('formats gaps under a minute with a sign', () => {
        expect(formatGap(482)).toBe('+0.482');
        expect(formatGap(12345)).toBe('+12.345');
    });

    it('formats gaps over a minute', () => {
        expect(formatGap(62345)).toBe('+1:02.345');
    });
});
