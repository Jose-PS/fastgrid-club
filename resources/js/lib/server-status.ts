export type ServerStatusKey = 'online' | 'offline' | 'unavailable';

export type ServerSessionKey = 'practice' | 'qualy' | 'race';

export type ServerStatus = {
    status: ServerStatusKey;
    players: number | null;
    maxPlayers: number | null;
    track: string | null;
    session: ServerSessionKey | null;
    ingameName: string | null;
    serverNow: string;
    fetchedAt: string | null;
};

export const STATUS_STALE_MS = 45_000;
export const STATUS_MAX_FAILURES = 3;

export function resolveServerStatus(
    status: ServerStatus,
    {
        nowMs,
        receivedAtMs,
        failures,
    }: { nowMs: number; receivedAtMs: number; failures: number },
): ServerStatus {
    if (status.status === 'unavailable') {
        return status;
    }

    const offsetMs = new Date(status.serverNow).getTime() - receivedAtMs;
    const ageMs =
        status.fetchedAt === null
            ? Number.POSITIVE_INFINITY
            : nowMs + offsetMs - new Date(status.fetchedAt).getTime();

    if (ageMs <= STATUS_STALE_MS && failures < STATUS_MAX_FAILURES) {
        return status;
    }

    return { ...status, status: 'unavailable', players: null };
}

export async function fetchServerStatus(url: string): Promise<ServerStatus> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(`Status request failed with ${response.status}`);
    }

    return (await response.json()) as ServerStatus;
}
