import { Link, usePage } from '@inertiajs/react';
import { CalendarDays, Home, Vote } from 'lucide-react';
import { useEffect } from 'react';
import type { ComponentType, ReactNode } from 'react';
import { AccountMenu } from '@/components/account-menu';
import { BrandSymbol } from '@/components/brand-symbol';
import { SocialLinks } from '@/components/social-links';
import type { SocialKey } from '@/components/social-links';
import { LocaleSwitcher } from '@/components/locale-switcher';
import { SignInButton } from '@/components/sign-in-button';
import { ThemeToggle } from '@/components/theme-toggle';
import { ToastRegion } from '@/components/toast-region';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { usePublicRoutes } from '@/hooks/use-public-routes';
import { useT } from '@/hooks/use-t';
import { zoneAbbreviation } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import type { Auth } from '@/types/auth';

type NavItem = {
    key: string;
    label: string;
    href: string;
    icon: ComponentType<{ className?: string }>;
};

const pathOf = (url: string): string => url.split(/[?#]/)[0];

const SOCIAL_NETWORKS: SocialKey[] = ['discord', 'instagram', 'x', 'bluesky'];

function useNavItems(): NavItem[] {
    const t = useT();
    const { home, calendar, proposals } = usePublicRoutes();

    return [
        { key: 'home', label: t('nav.home'), href: home().url, icon: Home },
        {
            key: 'calendar',
            label: t('nav.calendar'),
            href: calendar().url,
            icon: CalendarDays,
        },
        {
            key: 'proposals',
            label: t('nav.proposals'),
            href: proposals().url,
            icon: Vote,
        },
    ];
}

function Header({ items, user }: { items: NavItem[]; user: Auth['user'] }) {
    const t = useT();
    const { home } = usePublicRoutes();
    const path = pathOf(usePage().url);

    return (
        <header className="sticky top-0 z-30 border-b border-border bg-bg pt-[env(safe-area-inset-top,0px)]">
            <div className="mx-auto flex min-h-16 w-full max-w-page items-center gap-3 safe-x">
                <Link
                    href={home().url}
                    aria-label="FastGrid"
                    className="inline-flex min-h-11 items-center gap-2 text-fg"
                >
                    <BrandSymbol />
                    <span className="font-display text-[17px] leading-none font-bold tracking-[-0.01em]">
                        FastGrid
                    </span>
                </Link>
                <nav
                    aria-label={t('nav.main')}
                    className="ml-5 hidden gap-1 md:flex"
                >
                    {items.map((item) => (
                        <Link
                            key={item.key}
                            href={item.href}
                            aria-current={
                                path === item.href ? 'page' : undefined
                            }
                            className={cn(
                                'relative inline-flex min-h-11 items-center rounded-sm px-3.5 text-[15px] leading-none font-semibold transition-colors duration-150 ease-out hover:bg-surface-2 hover:text-fg',
                                path === item.href ? 'text-fg' : 'text-muted',
                            )}
                        >
                            {item.label}
                            {path === item.href ? (
                                <span
                                    aria-hidden="true"
                                    className="absolute inset-x-3.5 bottom-1 h-0.5 rounded-sm bg-primary"
                                />
                            ) : null}
                        </Link>
                    ))}
                </nav>
                <div className="ml-auto flex items-center gap-2">
                    <LocaleSwitcher />
                    <ThemeToggle />
                    <div className="hidden md:block">
                        {user ? <AccountMenu user={user} /> : <SignInButton />}
                    </div>
                </div>
            </div>
        </header>
    );
}

function BottomBar({ items, user }: { items: NavItem[]; user: Auth['user'] }) {
    const t = useT();
    const path = pathOf(usePage().url);

    return (
        <nav
            aria-label={t('nav.main')}
            className="fixed inset-x-0 bottom-0 z-35 border-t border-border bg-surface pb-[env(safe-area-inset-bottom,0px)] md:hidden"
        >
            <ul className="mx-auto grid w-full max-w-page grid-cols-4 px-1">
                {items.map((item) => (
                    <li key={item.key}>
                        <Link
                            href={item.href}
                            aria-current={
                                path === item.href ? 'page' : undefined
                            }
                            className={cn(
                                'relative flex min-h-[58px] w-full flex-col items-center justify-center gap-1 text-xs leading-none font-semibold',
                                path === item.href ? 'text-fg' : 'text-muted',
                            )}
                        >
                            {path === item.href ? (
                                <span
                                    aria-hidden="true"
                                    className="absolute inset-x-[28%] top-0 h-0.5 rounded-b-sm bg-primary"
                                />
                            ) : null}
                            <item.icon className="size-[22px]" />
                            {item.label}
                        </Link>
                    </li>
                ))}
                <li>
                    {user ? (
                        <AccountMenu user={user} placement="bar" />
                    ) : (
                        <SignInButton className="min-h-[58px] w-full flex-col gap-1 rounded-none border-0 bg-transparent px-0 text-xs text-primary-ink opacity-70" />
                    )}
                </li>
            </ul>
        </nav>
    );
}

function Footer() {
    const t = useT();

    return (
        <footer className="mt-2 border-t border-border pt-7 pb-24 md:pb-5">
            <div className="mx-auto flex w-full max-w-page flex-wrap items-center justify-between gap-x-5 gap-y-1.5 safe-x text-[13px] text-muted">
                <span>{t('foot.unaff')}</span>
                <SocialLinks networks={SOCIAL_NETWORKS} className="-mx-2" />
                <span className="mono">
                    {t('ui.tz', { zone: zoneAbbreviation(new Date()) })}
                </span>
            </div>
        </footer>
    );
}

export default function AppLayout({ children }: { children: ReactNode }) {
    const { url, props } = usePage<{ auth: Auth; locale: string }>();
    const items = useNavItems();
    const t = useT();

    useFlashToast();

    useEffect(() => {
        document.documentElement.lang = props.locale;
    }, [props.locale]);

    return (
        <>
            <a
                href="#contenido"
                className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:inline-flex focus:min-h-11 focus:items-center focus:rounded-sm focus:bg-primary focus:px-4 focus:font-semibold focus:text-on-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info"
            >
                {t('ui.skip')}
            </a>
            <Header items={items} user={props.auth.user} />
            <main
                id="contenido"
                tabIndex={-1}
                className="mx-auto min-h-[70vh] w-full max-w-page pt-7 safe-x pb-12 focus:outline-none md:pt-10 md:pb-18"
            >
                <div key={pathOf(url)} className="animate-enter">
                    {children}
                </div>
            </main>
            <Footer />
            <BottomBar items={items} user={props.auth.user} />
            <ToastRegion />
        </>
    );
}
