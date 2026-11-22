const EMPTY_LAP_TIME = '--:--.---';

function split(milliseconds: number): {
    minutes: number;
    seconds: number;
    millis: number;
} {
    const total = Math.round(milliseconds);

    return {
        minutes: Math.floor(total / 60000),
        seconds: Math.floor(total / 1000) % 60,
        millis: total % 1000,
    };
}

export function formatLapTime(milliseconds: number): string {
    if (!Number.isFinite(milliseconds) || milliseconds < 0) {
        return EMPTY_LAP_TIME;
    }

    const { minutes, seconds, millis } = split(milliseconds);

    return `${minutes}:${String(seconds).padStart(2, '0')}.${String(millis).padStart(3, '0')}`;
}

export function formatGap(milliseconds: number): string {
    if (!Number.isFinite(milliseconds) || milliseconds < 0) {
        return EMPTY_LAP_TIME;
    }

    const { minutes, seconds, millis } = split(milliseconds);
    const fraction = String(millis).padStart(3, '0');

    if (minutes === 0) {
        return `+${seconds}.${fraction}`;
    }

    return `+${minutes}:${String(seconds).padStart(2, '0')}.${fraction}`;
}
