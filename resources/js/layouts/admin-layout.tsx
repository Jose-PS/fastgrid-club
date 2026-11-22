import { Link, usePage } from '@inertiajs/react';
import {
    ExternalLink,
    Menu,
    PanelLeftClose,
    PanelLeftOpen,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { AccountMenu } from '@/components/account-menu';
import { AdminNavList } from '@/components/admin/admin-nav-list';
import { BrandSymbol } from '@/components/brand-symbol';
import { LocaleSwitcher } from '@/components/locale-switcher';
import { ThemeToggle } from '@/components/theme-toggle';
import { ToastRegion } from '@/components/toast-region';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { usePublicRoutes } from '@/hooks/use-public-routes';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';
import type { Auth } from '@/types/auth';

const COLLAPSED_KEY = 'admin-sidebar-collapsed';

const pathOf = (url: string): string => url.split(/[?#]/)[0];

const iconButton =
    'grid size-11 flex-none place-items-center rounded-sm border border-border text-fg transition-colors duration-150 ease-out hover:bg-surface-2';

function Brand({ showName = true }: { showName?: boolean }) {
    const t = useT();
    const { home } = usePublicRoutes();

    return (
        <Link
            href={home().url}
            aria-label="FastGrid"
            className="inline-flex min-h-11 items-center gap-2 text-fg"
        >
            <BrandSymbol />
            {showName ? (
                <span className="flex items-baseline gap-2">
                    <span className="font-display text-[17px] leading-none font-bold tracking-[-0.01em]">
                        FastGrid
                    </span>
                    <span className="text-xs leading-none font-semibold tracking-[0.08em] text-muted uppercase">
                        {t('nav.admin')}
                    </span>
                </span>
            ) : null}
        </Link>
    );
}

function ViewSiteLink({ className }: { className?: string }) {
    const t = useT();
    const { home } = usePublicRoutes();

    return (
        <Link
            href={home().url}
            className={cn(
                'inline-flex min-h-11 items-center gap-2 rounded-sm px-3 text-sm leading-none font-semibold text-fg transition-colors duration-150 ease-out hover:bg-surface-2',
                className,
            )}
        >
            <ExternalLink className="size-[18px]" aria-hidden="true" />
            {t('admin.nav.viewSite')}
        </Link>
    );
}

function MobileDrawer({
    open,
    onClose,
    user,
}: {
    open: boolean;
    onClose: () => void;
    user: Auth['user'];
}) {
    const t = useT();
    const dialog = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const element = dialog.current;

        if (!element) {
            return;
        }

        if (open && !element.open) {
            element.showModal();
        }

        if (!open && element.open) {
            element.close();
        }
    }, [open]);

    return (
        <dialog
            ref={dialog}
            aria-label={t('admin.nav.label')}
            onClose={onClose}
            onClick={(event) => {
                const rect = event.currentTarget.getBoundingClientRect();
                const outside =
                    event.clientX < rect.left ||
                    event.clientX > rect.right ||
                    event.clientY < rect.top ||
                    event.clientY > rect.bottom;

                if (outside) {
                    onClose();
                }
            }}
            className="m-0 h-dvh max-h-none w-[min(20rem,85vw)] max-w-none overflow-visible border-r border-border bg-surface p-0 text-fg backdrop:bg-black/50 md:hidden"
        >
            <div className="flex h-full flex-col pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]">
                <div className="flex items-center justify-between gap-2 border-b border-border px-3">
                    <Brand />
                    <button
                        type="button"
                        aria-label={t('admin.nav.close')}
                        onClick={onClose}
                        className={iconButton}
                    >
                        <X className="size-[22px]" aria-hidden="true" />
                    </button>
                </div>
                <div className="flex-1 overflow-y-auto px-2 py-4">
                    <AdminNavList />
                </div>
                <div className="grid gap-1 border-t border-border p-2">
                    <ViewSiteLink />
                    {user ? <AccountMenu user={user} placement="bar" /> : null}
                </div>
            </div>
        </dialog>
    );
}

export default function AdminLayout({ children }: { children: ReactNode }) {
    const { url, props } = usePage<{ auth: Auth; locale: string }>();
    const t = useT();
    const path = pathOf(url);
    const [collapsed, setCollapsed] = useState(false);
    const [drawerOpen, setDrawerOpen] = useState(false);

    useFlashToast();

    useEffect(() => {
        document.documentElement.lang = props.locale;
    }, [props.locale]);

    useEffect(() => {
        setCollapsed(window.localStorage.getItem(COLLAPSED_KEY) === '1');
    }, []);

    useEffect(() => {
        setDrawerOpen(false);
    }, [path]);

    const toggleCollapsed = (): void => {
        const next = !collapsed;

        setCollapsed(next);
        window.localStorage.setItem(COLLAPSED_KEY, next ? '1' : '0');
    };

    const user = props.auth.user;
    const CollapseIcon = collapsed ? PanelLeftOpen : PanelLeftClose;

    return (
        <>
            <a
                href="#contenido"
                className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:inline-flex focus:min-h-11 focus:items-center focus:rounded-sm focus:bg-primary focus:px-4 focus:font-semibold focus:text-on-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info"
            >
                {t('ui.skip')}
            </a>
            <div className="md:flex">
                <aside
                    className={cn(
                        'sticky top-0 hidden h-dvh flex-none flex-col border-r border-border bg-surface transition-[width] duration-150 ease-out md:flex',
                        collapsed ? 'w-18' : 'w-64',
                    )}
                >
                    <div
                        className={cn(
                            'flex items-center border-b border-border px-3',
                            collapsed ? 'justify-center' : 'justify-between',
                        )}
                    >
                        <Brand showName={!collapsed} />
                    </div>
                    <div className="flex-1 overflow-y-auto px-2 py-4">
                        <AdminNavList collapsed={collapsed} />
                    </div>
                    <div className="border-t border-border p-2">
                        <button
                            type="button"
                            aria-expanded={!collapsed}
                            aria-label={
                                collapsed
                                    ? t('admin.nav.expand')
                                    : t('admin.nav.collapse')
                            }
                            title={
                                collapsed
                                    ? t('admin.nav.expand')
                                    : t('admin.nav.collapse')
                            }
                            onClick={toggleCollapsed}
                            className={cn(
                                'flex min-h-11 w-full items-center gap-3 rounded-sm px-3 text-[15px] leading-none font-semibold text-muted transition-colors duration-150 ease-out hover:bg-surface-2 hover:text-fg',
                                collapsed && 'justify-center',
                            )}
                        >
                            <CollapseIcon
                                className="size-[18px] flex-none"
                                aria-hidden="true"
                            />
                            {collapsed ? null : (
                                <span>{t('admin.nav.collapse')}</span>
                            )}
                        </button>
                    </div>
                </aside>
                <div className="min-w-0 flex-1">
                    <header className="sticky top-0 z-30 border-b border-border bg-bg pt-[env(safe-area-inset-top,0px)]">
                        <div className="flex min-h-16 items-center gap-3 px-4 md:px-8">
                            <button
                                type="button"
                                aria-label={t('admin.nav.open')}
                                aria-expanded={drawerOpen}
                                onClick={() => setDrawerOpen(true)}
                                className={cn(iconButton, 'md:hidden')}
                            >
                                <Menu
                                    className="size-[22px]"
                                    aria-hidden="true"
                                />
                            </button>
                            <div className="md:hidden">
                                <Brand showName={false} />
                            </div>
                            <div className="ml-auto flex items-center gap-2">
                                <ViewSiteLink className="hidden md:inline-flex" />
                                <LocaleSwitcher />
                                <ThemeToggle />
                                <div className="hidden md:block">
                                    {user ? <AccountMenu user={user} /> : null}
                                </div>
                            </div>
                        </div>
                    </header>
                    <main
                        id="contenido"
                        tabIndex={-1}
                        className="mx-auto min-h-[70vh] w-full max-w-page px-4 pt-7 pb-12 focus:outline-none md:px-8 md:pt-10 md:pb-18"
                    >
                        <div key={path} className="animate-enter">
                            {children}
                        </div>
                    </main>
                </div>
            </div>
            <MobileDrawer
                open={drawerOpen}
                onClose={() => setDrawerOpen(false)}
                user={user}
            />
            <ToastRegion />
        </>
    );
}
