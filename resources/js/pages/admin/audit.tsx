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
import { index } from '@/routes/admin/audit';

type AuditEntry = {
    id: number;
    created_at: string | null;
    actor: string | null;
    action: string;
    subject: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
};

type AuditEntries = {
    data: AuditEntry[];
    current_page: number;
    last_page: number;
    total: number;
    prev_url: string | null;
    next_url: string | null;
};

type AuditFilters = {
    action: string;
    from: string;
    to: string;
};

function formatSnapshot(value: Record<string, unknown> | null): string {
    return value === null ? '—' : JSON.stringify(value, null, 2);
}

export default function AdminAudit({
    entries,
    actions,
    filters,
}: {
    entries: AuditEntries;
    actions: string[];
    filters: AuditFilters;
}) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const [form, setForm] = useState<AuditFilters>(filters);

    const query = (values: AuditFilters): Record<string, string> =>
        Object.fromEntries(
            Object.entries(values).filter(([, value]) => value !== ''),
        );

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        router.get(
            index({ query: query(form) }).url,
            {},
            { preserveState: true },
        );
    };

    const reset = (): void => {
        const empty = { action: '', from: '', to: '' };

        setForm(empty);
        router.get(index().url, {}, { preserveState: true });
    };

    const columns = [
        'admin.audit.colDate',
        'admin.audit.colActor',
        'admin.audit.colAction',
        'admin.audit.colSubject',
        'admin.audit.colBefore',
        'admin.audit.colAfter',
    ] as const;

    const linkClass = `${secondaryButtonClass} no-underline`;

    return (
        <>
            <Head title={t('admin.audit.title')} />
            <div className="grid gap-7">
                <div>
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('admin.audit.title')}
                    </h1>
                    <p className="mt-2 max-w-[60ch] text-muted">
                        {t('admin.audit.sub')}
                    </p>
                </div>
                <form
                    onSubmit={submit}
                    aria-label={t('admin.audit.filters')}
                    className="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_auto]"
                >
                    <div className="grid gap-1.5">
                        <label
                            htmlFor="audit-action"
                            className="text-sm font-semibold"
                        >
                            {t('admin.audit.filterAction')}
                        </label>
                        <select
                            id="audit-action"
                            value={form.action}
                            onChange={(event) =>
                                setForm({ ...form, action: event.target.value })
                            }
                            className={inputClass}
                        >
                            <option value="">
                                {t('admin.audit.allActions')}
                            </option>
                            {actions.map((action) => (
                                <option key={action} value={action}>
                                    {action}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <label
                            htmlFor="audit-from"
                            className="text-sm font-semibold"
                        >
                            {t('admin.audit.filterFrom')}
                        </label>
                        <input
                            id="audit-from"
                            type="date"
                            value={form.from}
                            onChange={(event) =>
                                setForm({ ...form, from: event.target.value })
                            }
                            className={inputClass}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <label
                            htmlFor="audit-to"
                            className="text-sm font-semibold"
                        >
                            {t('admin.audit.filterTo')}
                        </label>
                        <input
                            id="audit-to"
                            type="date"
                            value={form.to}
                            onChange={(event) =>
                                setForm({ ...form, to: event.target.value })
                            }
                            className={inputClass}
                        />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="submit" className={primaryButtonClass}>
                            {t('admin.audit.apply')}
                        </button>
                        <button
                            type="button"
                            onClick={reset}
                            className={secondaryButtonClass}
                        >
                            {t('admin.audit.reset')}
                        </button>
                    </div>
                </form>
                <section
                    aria-labelledby="audit-list-title"
                    className="grid gap-4"
                >
                    <h2 id="audit-list-title" className={sectionTitleClass}>
                        {t('admin.audit.list', { total: entries.total })}
                    </h2>
                    {entries.data.length === 0 ? (
                        <p className="text-muted">{t('admin.audit.empty')}</p>
                    ) : (
                        <div
                            className="overflow-x-auto rounded-md border border-border"
                            tabIndex={0}
                            role="region"
                            aria-labelledby="audit-list-title"
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
                                    {entries.data.map((entry) => (
                                        <tr
                                            key={entry.id}
                                            className="border-b border-border align-top last:border-b-0"
                                        >
                                            <td className="px-3 py-2.5 whitespace-nowrap text-muted">
                                                {entry.created_at === null
                                                    ? '—'
                                                    : formatDateTime(
                                                          new Date(
                                                              entry.created_at,
                                                          ),
                                                          locale,
                                                          {
                                                              day: 'numeric',
                                                              month: 'short',
                                                              year: 'numeric',
                                                          },
                                                      )}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {entry.actor ??
                                                    t('admin.audit.system')}
                                            </td>
                                            <td className="px-3 py-2.5 mono">
                                                {entry.action}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {entry.subject ?? '—'}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <pre className="mono text-xs whitespace-pre-wrap">
                                                    {formatSnapshot(
                                                        entry.before,
                                                    )}
                                                </pre>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <pre className="mono text-xs whitespace-pre-wrap">
                                                    {formatSnapshot(
                                                        entry.after,
                                                    )}
                                                </pre>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {entries.last_page > 1 ? (
                        <nav
                            aria-label={t('admin.audit.pagination')}
                            className="flex flex-wrap items-center justify-between gap-3"
                        >
                            {entries.prev_url ? (
                                <Link
                                    href={entries.prev_url}
                                    className={linkClass}
                                >
                                    {t('admin.audit.prev')}
                                </Link>
                            ) : (
                                <span />
                            )}
                            <span className="text-sm text-muted">
                                {t('admin.audit.pageOf', {
                                    page: entries.current_page,
                                    pages: entries.last_page,
                                })}
                            </span>
                            {entries.next_url ? (
                                <Link
                                    href={entries.next_url}
                                    className={linkClass}
                                >
                                    {t('admin.audit.next')}
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
