import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPoller, nextDelay } from './poller';

describe('nextDelay', () => {
    it('uses the base interval without failures', () => {
        expect(nextDelay(0)).toBe(15_000);
    });

    it('doubles after each failure up to the cap', () => {
        expect(nextDelay(1)).toBe(30_000);
        expect(nextDelay(2)).toBe(60_000);
        expect(nextDelay(3)).toBe(120_000);
        expect(nextDelay(10)).toBe(120_000);
    });
});

describe('createPoller', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('runs every interval while active', async () => {
        const run = vi.fn().mockResolvedValue(undefined);
        const poller = createPoller({ run, isActive: () => true });

        poller.start();
        await vi.advanceTimersByTimeAsync(15_000);
        await vi.advanceTimersByTimeAsync(15_000);

        expect(run).toHaveBeenCalledTimes(2);
        poller.stop();
    });

    it('backs off after errors and recovers after a success', async () => {
        const run = vi
            .fn()
            .mockRejectedValueOnce(new Error('down'))
            .mockResolvedValue(undefined);
        const poller = createPoller({ run, isActive: () => true });

        poller.start();
        await vi.advanceTimersByTimeAsync(15_000);
        expect(run).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(15_000);
        expect(run).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(15_000);
        expect(run).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(15_000);
        expect(run).toHaveBeenCalledTimes(3);
        poller.stop();
    });

    it('pauses while inactive and resumes on wake', async () => {
        const run = vi.fn().mockResolvedValue(undefined);
        let active = false;
        const poller = createPoller({ run, isActive: () => active });

        poller.start();
        await vi.advanceTimersByTimeAsync(60_000);
        expect(run).not.toHaveBeenCalled();

        active = true;
        poller.wake();
        await vi.advanceTimersByTimeAsync(0);
        expect(run).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(15_000);
        expect(run).toHaveBeenCalledTimes(2);
        poller.stop();
    });

    it('does not run after stop', async () => {
        const run = vi.fn().mockResolvedValue(undefined);
        const poller = createPoller({ run, isActive: () => true });

        poller.start();
        poller.stop();
        await vi.advanceTimersByTimeAsync(60_000);

        expect(run).not.toHaveBeenCalled();
    });
});
