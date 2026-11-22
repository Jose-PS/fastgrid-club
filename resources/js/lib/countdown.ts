export type CountdownParts = {
    totalMs: number;
    days: number;
    hours: number;
    minutes: number;
    seconds: number;
    done: boolean;
};

const SECOND = 1000;
const MINUTE = 60 * SECOND;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

export function clockOffset(serverNowMs: number, clientNowMs: number): number {
    return serverNowMs - clientNowMs;
}

export function getCountdown(
    targetMs: number,
    clientNowMs: number,
    offsetMs = 0,
): CountdownParts {
    const totalMs = Math.max(0, targetMs - (clientNowMs + offsetMs));
    const days = Math.floor(totalMs / DAY);
    const hours = Math.floor((totalMs % DAY) / HOUR);
    const minutes = Math.floor((totalMs % HOUR) / MINUTE);
    const seconds = Math.floor((totalMs % MINUTE) / SECOND);

    return { totalMs, days, hours, minutes, seconds, done: totalMs === 0 };
}

export function formatCountdownUnit(value: number): string {
    return String(Math.max(0, Math.trunc(value))).padStart(2, '0');
}
