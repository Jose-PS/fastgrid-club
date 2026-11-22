import type { FlashToast } from '@/types/ui';

export type ToastItem = FlashToast & { id: number };

const TOAST_DURATION_MS = 2800;

const listeners = new Set<() => void>();

let current: ToastItem | null = null;
let nextId = 1;
let timer: ReturnType<typeof setTimeout> | undefined;

function notify(): void {
    listeners.forEach((listener) => listener());
}

export function showToast(toast: FlashToast): void {
    clearTimeout(timer);
    current = { ...toast, id: nextId++ };
    timer = setTimeout(() => {
        current = null;
        notify();
    }, TOAST_DURATION_MS);
    notify();
}

export function getToast(): ToastItem | null {
    return current;
}

export function subscribeToast(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}
