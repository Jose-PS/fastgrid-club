import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    inputClass,
    primaryButtonClass,
    secondaryButtonClass,
    sectionTitleClass,
} from '@/components/account-styles';
import { useT } from '@/hooks/use-t';
import { formatDateTime } from '@/lib/datetime';
import { index } from '@/routes/admin/users';

type AdminUser = {
    id: number;
    name: string;
    email: string | null;
    email_verified: boolean;
    has_google: boolean;
    has_steam: boolean;
    passkeys_count: number;
    driver_names: string | null;
    laps_count: number;
    votes_count: number;
    proposals_count: number;
    is_admin: boolean;
    created_at: string | null;
    last_login_at: string | null;
};

type AdminUsers = {
    data: AdminUser[];
    current_page: number;
    last_page: number;
    total: number;
    prev_url: string | null;
    next_url: string | null;
};

type UsersFilters = {
    search: string;
};

export default function AdminUsersPage({
    users,
    filters,
}: {
    users: AdminUsers;
    filters: UsersFilters;
}) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const [search, setSearch] = useState<string>(filters.search);

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        router.get(
            index({ query: search === '' ? {} : { search } }).url,
            {},
            { preserveState: true },
        );
    };

    const reset = (): void => {
        setSearch('');
        router.get(index().url, {}, { preserveState: true });
    };

    const columns = [
        'admin.users.colName',
        'admin.users.colEmail',
        'admin.users.colVerified',
        'admin.users.colGoogle',
        'admin.users.colSteam',
        'admin.users.colPasskeys',
        'admin.users.colDriver',
        'admin.users.colLaps',
        'admin.users.colVotes',
        'admin.users.colProposals',
        'admin.users.colAdmin',
        'admin.users.colCreated',
        'admin.users.colLastLogin',
    ] as const;

    const yesNo = (value: boolean): string =>
        value ? t('admin.users.yes') : t('admin.users.no');

    const linkClass = `${secondaryButtonClass} no-underline`;

    return (
        <>
            <Head title={t('admin.users.title')} />
            <div className="grid gap-7">
                <div>
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('admin.users.title')}
                    </h1>
                    <p className="mt-2 max-w-[60ch] text-muted">
                        {t('admin.users.sub')}
                    </p>
                </div>
                <form
                    onSubmit={submit}
                    aria-label={t('admin.users.filters')}
                    className="grid items-end gap-4 sm:grid-cols-[1fr_auto]"
                >
                    <div className="grid gap-1.5">
                        <label
                            htmlFor="users-search"
                            className="text-sm font-semibold"
                        >
                            {t('admin.users.search')}
                        </label>
                        <input
                            id="users-search"
                            type="search"
                            value={search}
                            maxLength={100}
                            onChange={(event) => setSearch(event.target.value)}
                            className={inputClass}
                        />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="submit" className={primaryButtonClass}>
                            {t('admin.users.apply')}
                        </button>
                        <button
                            type="button"
                            onClick={reset}
                            className={secondaryButtonClass}
                        >
                            {t('admin.users.reset')}
                        </button>
                    </div>
                </form>
                <section
                    aria-labelledby="users-list-title"
                    className="grid gap-4"
                >
                    <h2 id="users-list-title" className={sectionTitleClass}>
                        {t('admin.users.list', { total: users.total })}
                    </h2>
                    {users.data.length === 0 ? (
                        <p className="text-muted">{t('admin.users.empty')}</p>
                    ) : (
                        <div
                            className="overflow-x-auto rounded-md border border-border"
                            tabIndex={0}
                            role="region"
                            aria-labelledby="users-list-title"
                        >
                            <table className="w-full border-collapse text-left text-sm">
                                <thead>
                                    <tr className="border-b border-border bg-surface text-muted">
                                        {columns.map((column) => (
                                            <th
                                                key={column}
                                                scope="col"
                                                className="px-3 py-2.5 font-semibold"
                                            >
                                                {t(column)}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {users.data.map((user) => (
                                        <tr
                                            key={user.id}
                                            className="border-b border-border align-top last:border-b-0"
                                        >
                                            <td className="px-3 py-2.5">
                                                {user.name}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {user.email ?? '—'}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {yesNo(user.email_verified)}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {yesNo(user.has_google)}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {yesNo(user.has_steam)}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {user.passkeys_count}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {user.driver_names ?? '—'}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {user.laps_count}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {user.votes_count}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {user.proposals_count}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {yesNo(user.is_admin)}
                                            </td>
                                            <td className="px-3 py-2.5 whitespace-nowrap text-muted">
                                                {user.created_at === null
                                                    ? '—'
                                                    : formatDateTime(
                                                          new Date(
                                                              user.created_at,
                                                          ),
                                                          locale,
                                                          {
                                                              day: 'numeric',
                                                              month: 'short',
                                                              year: 'numeric',
                                                          },
                                                      )}
                                            </td>
                                            <td className="px-3 py-2.5 whitespace-nowrap text-muted">
                                                {user.last_login_at === null
                                                    ? '—'
                                                    : formatDateTime(
                                                          new Date(
                                                              user.last_login_at,
                                                          ),
                                                          locale,
                                                          {
                                                              day: 'numeric',
                                                              month: 'short',
                                                              year: 'numeric',
                                                          },
                                                      )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {users.last_page > 1 ? (
                        <nav
                            aria-label={t('admin.users.pagination')}
                            className="flex flex-wrap items-center justify-between gap-3"
                        >
                            {users.prev_url ? (
                                <Link
                                    href={users.prev_url}
                                    className={linkClass}
                                >
                                    {t('admin.users.prev')}
                                </Link>
                            ) : (
                                <span />
                            )}
                            <span className="text-sm text-muted">
                                {t('admin.users.pageOf', {
                                    page: users.current_page,
                                    pages: users.last_page,
                                })}
                            </span>
                            {users.next_url ? (
                                <Link
                                    href={users.next_url}
                                    className={linkClass}
                                >
                                    {t('admin.users.next')}
                                </Link>
                            ) : (
                                <span />
                            )}
                        </nav>
                    ) : null}
                </section>
            </div>
        </>
    );
}
