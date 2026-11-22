import { Check, CircleAlert, Info, TriangleAlert } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { getToast, subscribeToast } from '@/lib/toast';
import type { FlashToast } from '@/types/ui';

const ICONS = {
    success: Check,
    info: Info,
    warning: TriangleAlert,
    error: CircleAlert,
} satisfies Record<FlashToast['type'], typeof Check>;

export function ToastRegion() {
    const toast = useSyncExternalStore(subscribeToast, getToast, () => null);
    const Icon = toast ? ICONS[toast.type] : null;

    return (
        <div
            role="status"
            className="pointer-events-none fixed inset-x-0 bottom-[calc(80px+env(safe-area-inset-bottom,0px))] z-60 flex justify-center px-4 md:bottom-8"
        >
            {toast && Icon ? (
                <div
                    key={toast.id}
                    className="pointer-events-auto flex max-w-full animate-enter items-center gap-2 rounded-md bg-fg px-4 py-3 text-sm font-semibold text-bg"
                >
                    <Icon
                        className="size-[18px] flex-none"
                        aria-hidden="true"
                    />
                    <span>{toast.message}</span>
                </div>
            ) : null}
        </div>
    );
}
