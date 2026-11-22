import { describe, expect, it } from 'vitest';
import { clockOffset, formatCountdownUnit, getCountdown } from './countdown';

const SECOND = 1000;
const MINUTE = 60 * SECOND;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

describe('getCountdown', () => {
    it('splits the remaining time into parts', () => {
        const now = 1_000_000;
        const target = now + 2 * DAY + 3 * HOUR + 4 * MINUTE + 5 * SECOND;

        expect(getCountdown(target, now)).toEqual({
            totalMs: target - now,
            days: 2,
            hours: 3,
            minutes: 4,
            seconds: 5,
            done: false,
        });
    });

    it('never goes negative once the target has passed', () => {
        expect(getCountdown(1000, 5000)).toMatchObject({
            totalMs: 0,
            days: 0,
            hours: 0,
            minutes: 0,
            seconds: 0,
            done: true,
        });
    });

    it('drops partial seconds', () => {
        expect(getCountdown(10_999, 10_000).seconds).toBe(0);
        expect(getCountdown(11_000, 10_000).seconds).toBe(1);
    });

    it('corrects a client clock that runs behind the server', () => {
        const serverNow = 10 * HOUR;
        const clientNow = serverNow - 30 * SECOND;
        const offset = clockOffset(serverNow, clientNow);
        const target = serverNow + MINUTE;

        expect(offset).toBe(30 * SECOND);
        expect(getCountdown(target, clientNow).seconds).toBe(30);
        expect(getCountdown(target, clientNow, offset).minutes).toBe(1);
        expect(getCountdown(target, clientNow, offset).seconds).toBe(0);
    });

    it('corrects a client clock that runs ahead of the server', () => {
        const serverNow = 10 * HOUR;
        const clientNow = serverNow + 2 * MINUTE;
        const offset = clockOffset(serverNow, clientNow);
        const target = serverNow + 5 * MINUTE;

        expect(offset).toBe(-2 * MINUTE);
        expect(getCountdown(target, clientNow, offset).minutes).toBe(5);
    });

    it('keeps the corrected value as the client clock advances', () => {
        const serverNow = 10 * HOUR;
        const clientNow = serverNow + 7 * SECOND;
        const offset = clockOffset(serverNow, clientNow);
        const target = serverNow + HOUR;

        const later = getCountdown(target, clientNow + 10 * SECOND, offset);

        expect(later.minutes).toBe(59);
        expect(later.seconds).toBe(50);
    });
});

describe('formatCountdownUnit', () => {
    it('pads single digits to two characters', () => {
        expect(formatCountdownUnit(0)).toBe('00');
        expect(formatCountdownUnit(7)).toBe('07');
        expect(formatCountdownUnit(42)).toBe('42');
    });

    it('never renders a negative value', () => {
        expect(formatCountdownUnit(-3)).toBe('00');
    });
});
