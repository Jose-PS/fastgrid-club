import { Link, usePage } from '@inertiajs/react';
import { useAdminNavGroups } from '@/components/admin/admin-nav-items';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';

const pathOf = (url: string): string => url.split(/[?#]/)[0];

type AdminNavListProps = {
    collapsed?: boolean;
};

export function AdminNavList({ collapsed = false }: AdminNavListProps) {
    const t = useT();
    const path = pathOf(usePage().url);
    const groups = useAdminNavGroups();

    return (
        <nav aria-label={t('admin.nav.label')} className="grid gap-5">
            {groups.map((group) => (
                <div key={group.key} className="grid gap-1">
                    <p
                        className={cn(
                            'px-3 text-xs leading-none font-semibold tracking-[0.08em] text-muted uppercase',
                            collapsed && 'sr-only',
                        )}
                    >
                        {group.label}
                    </p>
                    <ul className="grid gap-1">
                        {group.items.map((item) => {
                            const active = path === item.href;

                            return (
                                <li key={item.key}>
                                    <Link
                                        href={item.href}
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                        aria-label={
                                            collapsed ? item.label : undefined
                                        }
                                        title={
                                            collapsed ? item.label : undefined
                                        }
                                        className={cn(
                                            'relative flex min-h-11 items-center gap-3 rounded-sm px-3 text-[15px] leading-none font-semibold transition-colors duration-150 ease-out hover:bg-surface-2 hover:text-fg',
                                            collapsed && 'justify-center',
                                            active
                                                ? 'bg-surface-2 text-fg'
                                                : 'text-muted',
                                        )}
                                    >
                                        {active ? (
                                            <span
                                                aria-hidden="true"
                                                className="absolute inset-y-2 left-0 w-0.5 rounded-sm bg-primary"
                                            />
                                        ) : null}
                                        <item.icon
                                            className="size-[18px] flex-none"
                                            aria-hidden="true"
                                        />
                                        {collapsed ? null : (
                                            <span className="truncate">
                                                {item.label}
                                            </span>
                                        )}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </nav>
    );
}
