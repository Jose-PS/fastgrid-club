import { Link, router } from '@inertiajs/react';
import { LogOut, ShieldCheck, UserRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import { index as admin } from '@/routes/admin';
import { show } from '@/routes/account';
import type { User } from '@/types/auth';

type AccountMenuProps = {
    user: User;
    placement?: 'header' | 'bar';
};

function initialsOf(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0])
        .join('')
        .toUpperCase();
}

export function AccountMenu({ user, placement = 'header' }: AccountMenuProps) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const container = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') {
                event.preventDefault();
                setOpen(false);
            }
        };

        const onPointerDown = (event: PointerEvent): void => {
            if (!container.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('keydown', onKeyDown);
        document.addEventListener('pointerdown', onPointerDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.removeEventListener('pointerdown', onPointerDown);
        };
    }, [open]);

    const isBar = placement === 'bar';

    return (
        <div ref={container} className="relative">
            <button
                type="button"
                aria-expanded={open}
                aria-label={`${t('nav.account')}: ${user.name}`}
                onClick={() => setOpen(!open)}
                className={cn(
                    isBar
                        ? 'flex min-h-[58px] w-full flex-col items-center justify-center gap-1 text-xs leading-none font-semibold text-fg'
                        : 'inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border border-border-strong px-3 text-sm leading-none font-semibold text-fg transition-colors duration-150 ease-out hover:bg-surface-2',
                )}
            >
                <span className="grid size-8 flex-none place-items-center rounded-full border border-border-strong bg-surface-2 font-display text-xs leading-none font-bold">
                    {initialsOf(user.name)}
                </span>
                <span className={cn(isBar && 'max-w-full truncate px-1')}>
                    {user.name}
                </span>
            </button>
            {open ? (
                <div
                    role="group"
                    aria-label={t('nav.account')}
                    className={cn(
                        'absolute z-50 min-w-45 rounded-md border border-border bg-surface p-1.5',
                        isBar
                            ? 'right-2 bottom-[calc(100%+8px)]'
                            : 'top-[calc(100%+6px)] right-0',
                    )}
                >
                    <p className="px-2.5 py-2 text-[13px] text-muted">
                        {t('nav.signedAs')} {user.name}
                    </p>
                    <Link
                        href={show().url}
                        onClick={() => setOpen(false)}
                        className="flex min-h-11 w-full items-center gap-2 rounded-sm px-3 text-sm font-semibold text-fg hover:bg-surface-2"
                    >
                        <UserRound className="size-[18px]" aria-hidden="true" />
                        {t('nav.account')}
                    </Link>
                    {user.is_admin ? (
                        <Link
                            href={admin().url}
                            onClick={() => setOpen(false)}
                            className="flex min-h-11 w-full items-center gap-2 rounded-sm px-3 text-sm font-semibold text-fg hover:bg-surface-2"
                        >
                            <ShieldCheck
                                className="size-[18px]"
                                aria-hidden="true"
                            />
                            {t('nav.admin')}
                        </Link>
                    ) : null}
                    <button
                        type="button"
                        onClick={() => {
                            setOpen(false);
                            router.post(logout().url);
                        }}
                        className="flex min-h-11 w-full items-center gap-2 rounded-sm px-3 text-sm font-semibold text-fg hover:bg-surface-2"
                    >
                        <LogOut className="size-[18px]" aria-hidden="true" />
                        {t('nav.signout')}
                    </button>
                </div>
            ) : null}
        </div>
    );
}
