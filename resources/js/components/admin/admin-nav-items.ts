import {
    BookOpen,
    CalendarClock,
    CalendarDays,
    Coins,
    LayoutDashboard,
    ScrollText,
    Server,
    Settings2,
    Share2,
    Users,
    Vote,
    Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useT } from '@/hooks/use-t';
import { index as panel } from '@/routes/admin';
import { index as audit } from '@/routes/admin/audit';
import { index as catalog } from '@/routes/admin/catalog';
import { show as defaultConfig } from '@/routes/admin/default-config';
import { show as override } from '@/routes/admin/override';
import { show as donations } from '@/routes/admin/donations';
import { index as proposals } from '@/routes/admin/proposals';
import { index as schedule } from '@/routes/admin/schedule';
import { index as servers } from '@/routes/admin/servers';
import { index as social } from '@/routes/admin/social';
import { index as users } from '@/routes/admin/users';
import { index as weeks } from '@/routes/admin/weeks';

export type AdminNavItem = {
    key: string;
    label: string;
    href: string;
    icon: LucideIcon;
};

export type AdminNavGroup = {
    key: string;
    label: string;
    items: AdminNavItem[];
};

export function useAdminNavGroups(): AdminNavGroup[] {
    const t = useT();

    return [
        {
            key: 'server',
            label: t('admin.nav.group.server'),
            items: [
                {
                    key: 'panel',
                    label: t('admin.nav.panel'),
                    href: panel().url,
                    icon: LayoutDashboard,
                },
                {
                    key: 'override',
                    label: t('admin.nav.override'),
                    href: override().url,
                    icon: Zap,
                },
                {
                    key: 'schedule',
                    label: t('admin.schedule.title'),
                    href: schedule().url,
                    icon: CalendarClock,
                },
                {
                    key: 'servers',
                    label: t('admin.servers.title'),
                    href: servers().url,
                    icon: Server,
                },
            ],
        },
        {
            key: 'season',
            label: t('admin.nav.group.season'),
            items: [
                {
                    key: 'weeks',
                    label: t('admin.weeks.title'),
                    href: weeks().url,
                    icon: CalendarDays,
                },
                {
                    key: 'proposals',
                    label: t('admin.proposals.title'),
                    href: proposals().url,
                    icon: Vote,
                },
                {
                    key: 'default-config',
                    label: t('admin.defaultConfig.title'),
                    href: defaultConfig().url,
                    icon: Settings2,
                },
            ],
        },
        {
            key: 'catalog',
            label: t('admin.nav.group.catalog'),
            items: [
                {
                    key: 'catalog',
                    label: t('admin.catalog.title'),
                    href: catalog().url,
                    icon: BookOpen,
                },
            ],
        },
        {
            key: 'community',
            label: t('admin.nav.group.community'),
            items: [
                {
                    key: 'users',
                    label: t('admin.users.title'),
                    href: users().url,
                    icon: Users,
                },
                {
                    key: 'donations',
                    label: t('admin.donations.title'),
                    href: donations().url,
                    icon: Coins,
                },
                {
                    key: 'social',
                    label: t('admin.social.title'),
                    href: social().url,
                    icon: Share2,
                },
            ],
        },
        {
            key: 'system',
            label: t('admin.nav.group.system'),
            items: [
                {
                    key: 'audit',
                    label: t('admin.audit.title'),
                    href: audit().url,
                    icon: ScrollText,
                },
            ],
        },
    ];
}
