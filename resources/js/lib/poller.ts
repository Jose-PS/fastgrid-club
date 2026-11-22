export const STATUS_REFRESH_MS = 15_000;
export const STATUS_MAX_BACKOFF_MS = 120_000;

export function nextDelay(
    failures: number,
    intervalMs = STATUS_REFRESH_MS,
    maxDelayMs = STATUS_MAX_BACKOFF_MS,
): number {
    return Math.min(maxDelayMs, intervalMs * 2 ** Math.max(0, failures));
}

export type PollerOptions = {
    run: () => Promise<void>;
    isActive: () => boolean;
    intervalMs?: number;
    maxDelayMs?: number;
};

export type Poller = {
    start: () => void;
    stop: () => void;
    wake: () => void;
};

export function createPoller({
    run,
    isActive,
    intervalMs = STATUS_REFRESH_MS,
    maxDelayMs = STATUS_MAX_BACKOFF_MS,
}: PollerOptions): Poller {
    let timer: ReturnType<typeof setTimeout> | null = null;
    let running = false;
    let busy = false;
    let failures = 0;

    const clear = () => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const schedule = () => {
        clear();

        if (running) {
            timer = setTimeout(
                tick,
                nextDelay(failures, intervalMs, maxDelayMs),
            );
        }
    };

    async function tick() {
        timer = null;

        if (!running || busy) {
            return;
        }

        if (!isActive()) {
            return;
        }

        busy = true;

        try {
            await run();
            failures = 0;
        } catch {
            failures += 1;
        } finally {
            busy = false;
        }

        schedule();
    }

    return {
        start() {
            running = true;
            schedule();
        },
        stop() {
            running = false;
            clear();
        },
        wake() {
            if (running && timer === null && !busy && isActive()) {
                void tick();
            }
        },
    };
}
