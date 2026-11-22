import { useEffect, useRef, useState } from 'react';
import { useClock } from '@/hooks/use-clock';
import { createPoller } from '@/lib/poller';
import { fetchServerStatus, resolveServerStatus } from '@/lib/server-status';
import type { ServerStatus } from '@/lib/server-status';
import { status as statusRoute } from '@/routes/api';

export function useServerStatus(initial: ServerStatus): {
    status: ServerStatus;
    receivedAtMs: number;
} {
    const [state, setState] = useState({
        status: initial,
        receivedAtMs: Date.now(),
        failures: 0,
    });
    const nowMs = useClock(5000);
    const lastInitial = useRef(initial);

    useEffect(() => {
        if (lastInitial.current !== initial) {
            lastInitial.current = initial;
            setState({
                status: initial,
                receivedAtMs: Date.now(),
                failures: 0,
            });
        }
    }, [initial]);

    useEffect(() => {
        const poller = createPoller({
            run: async () => {
                try {
                    const next = await fetchServerStatus(statusRoute().url);
                    setState({
                        status: next,
                        receivedAtMs: Date.now(),
                        failures: 0,
                    });
                } catch (error) {
                    setState((current) => ({
                        ...current,
                        failures: current.failures + 1,
                    }));

                    throw error;
                }
            },
            isActive: () =>
                document.visibilityState === 'visible' && navigator.onLine,
        });
        const wake = () => poller.wake();

        poller.start();
        document.addEventListener('visibilitychange', wake);
        window.addEventListener('online', wake);

        return () => {
            poller.stop();
            document.removeEventListener('visibilitychange', wake);
            window.removeEventListener('online', wake);
        };
    }, []);

    return {
        status: resolveServerStatus(state.status, {
            nowMs: Math.max(nowMs, state.receivedAtMs),
            receivedAtMs: state.receivedAtMs,
            failures: state.failures,
        }),
        receivedAtMs: state.receivedAtMs,
    };
}
