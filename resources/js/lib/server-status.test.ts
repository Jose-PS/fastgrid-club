import { describe, expect, it } from 'vitest';
import {
    resolveServerStatus,
    STATUS_MAX_FAILURES,
    STATUS_STALE_MS,
} from './server-status';
import type { ServerStatus } from './server-status';

const SERVER_NOW = '2026-11-04T12:00:00+01:00';
const RECEIVED_AT = new Date(SERVER_NOW).getTime() + 3_600_000;

function online(overrides: Partial<ServerStatus> = {}): ServerStatus {
    return {
        status: 'online',
        players: 12,
        maxPlayers: 20,
        track: 'Imola GP',
        session: 'practice',
        ingameName: 'FastGrid',
        serverNow: SERVER_NOW,
        fetchedAt: '2026-11-04T11:59:50+01:00',
        ...overrides,
    };
}

const fresh = { nowMs: RECEIVED_AT, receivedAtMs: RECEIVED_AT, failures: 0 };

describe('resolveServerStatus', () => {
    it('keeps a recent online status untouched', () => {
        const status = online();

        expect(resolveServerStatus(status, fresh)).toBe(status);
    });

    it('turns unavailable after the staleness window, keeping the last data time', () => {
        const status = online();
        const result = resolveServerStatus(status, {
            ...fresh,
            nowMs: RECEIVED_AT + STATUS_STALE_MS,
        });

        expect(result.status).toBe('unavailable');
        expect(result.players).toBeNull();
        expect(result.fetchedAt).toBe(status.fetchedAt);
        expect(result.track).toBe('Imola GP');
    });

    it('stays online right at the window edge', () => {
        const result = resolveServerStatus(online(), {
            ...fresh,
            nowMs: RECEIVED_AT + STATUS_STALE_MS - 10_000,
        });

        expect(result.status).toBe('online');
    });

    it('turns unavailable after consecutive failed polls even with a recent snapshot', () => {
        const status = online();

        expect(
            resolveServerStatus(status, {
                ...fresh,
                failures: STATUS_MAX_FAILURES - 1,
            }),
        ).toBe(status);
        expect(
            resolveServerStatus(status, {
                ...fresh,
                failures: STATUS_MAX_FAILURES,
            }).status,
        ).toBe('unavailable');
    });

    it('compensates for a client clock that differs from the server', () => {
        const skewedReceived = RECEIVED_AT + 600_000;
        const status = online();

        expect(
            resolveServerStatus(status, {
                nowMs: skewedReceived + 5_000,
                receivedAtMs: skewedReceived,
                failures: 0,
            }),
        ).toBe(status);
    });

    it('treats offline as is when there is a snapshot but marks no snapshot as unavailable', () => {
        const offline = online({ status: 'offline', players: null });

        expect(resolveServerStatus(offline, fresh).status).toBe('offline');
        expect(
            resolveServerStatus(online({ fetchedAt: null }), fresh).status,
        ).toBe('unavailable');
    });

    it('returns an already unavailable status unchanged', () => {
        const status = online({ status: 'unavailable', players: null });

        expect(resolveServerStatus(status, fresh)).toBe(status);
    });
});
