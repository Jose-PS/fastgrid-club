import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    fieldErrorClass,
    inputClass,
    primaryButtonClass,
    sectionClass,
    sectionTitleClass,
} from '@/components/account-styles';
import { Tabs } from '@/components/ui/tabs';
import { useT } from '@/hooks/use-t';
import { formatDate, formatDateTime } from '@/lib/datetime';
import type { DonationPeriod, DonationPlatform } from '@/lib/donation';
import { centsToEurosInput, eurosToCents, formatEuros } from '@/lib/donation';
import { showToast } from '@/lib/toast';
import { update } from '@/routes/admin/donations';

type AdminMethod = {
    platform: DonationPlatform;
    url: string | null;
    domain: string;
};

type DonationsForm = {
    urls: Record<DonationPlatform, string>;
    goal: string;
    covered: string;
};

function monthLabel(month: string, locale: string): string {
    return formatDate(new Date(`${month}-15T12:00:00Z`), locale, {
        month: 'long',
        year: 'numeric',
    });
}

export default function AdminDonations({
    methods,
    period,
    history,
}: {
    methods: AdminMethod[];
    period: DonationPeriod;
    history: DonationPeriod[];
}) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const form = useForm<DonationsForm>({
        urls: {
            ko_fi: methods.find((m) => m.platform === 'ko_fi')?.url ?? '',
            patreon: methods.find((m) => m.platform === 'patreon')?.url ?? '',
            paypal: methods.find((m) => m.platform === 'paypal')?.url ?? '',
        },
        goal: centsToEurosInput(period.goal_cents),
        covered: centsToEurosInput(period.covered_cents),
    });
    const [amountErrors, setAmountErrors] = useState<
        Record<string, string | undefined>
    >({});
    const errors: Record<string, string | undefined> = {
        ...form.errors,
        ...amountErrors,
    };

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        const goalCents = eurosToCents(form.data.goal);
        const coveredCents = eurosToCents(form.data.covered);

        form.clearErrors();

        if (goalCents === null || coveredCents === null) {
            const message = t('admin.donations.errAmount');

            setAmountErrors({
                goal_cents: goalCents === null ? message : undefined,
                covered_cents: coveredCents === null ? message : undefined,
            });

            return;
        }

        setAmountErrors({});
        form.transform((data) => ({
            urls: {
                ko_fi: data.urls.ko_fi.trim(),
                patreon: data.urls.patreon.trim(),
                paypal: data.urls.paypal.trim(),
            },
            goal_cents: goalCents,
            covered_cents: coveredCents,
        }));
        form.put(update().url, {
            preserveScroll: true,
            onSuccess: () =>
                showToast({
                    type: 'success',
                    message: t('admin.donations.saved'),
                }),
        });
    };

    const amountFields = [
        { id: 'goal', field: 'goal_cents', label: t('admin.donations.goal') },
        {
            id: 'covered',
            field: 'covered_cents',
            label: t('admin.donations.covered'),
        },
    ] as const;

    return (
        <>
            <Head title={t('admin.donations.title')} />
            <div className="grid gap-7">
                <div>
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('admin.donations.title')}
                    </h1>
                    <p className="mt-2 max-w-[60ch] text-muted">
                        {t('admin.donations.sub')}
                    </p>
                </div>
                <Tabs
                    label={t('admin.donations.tabsLabel')}
                    queryKey="tab"
                    defaultId="settings"
                    items={[
                        {
                            id: 'settings',
                            label: t('admin.donations.tabSettings'),
                            panel: (
                                <form
                                    onSubmit={submit}
                                    noValidate
                                    className="grid gap-6 lg:grid-cols-2 lg:items-start"
                                >
                                    <div className="sticky top-[calc(4rem+1px+env(safe-area-inset-top,0px))] z-20 -mx-4 border-b border-border bg-bg px-4 py-3 md:-mx-8 md:px-8 lg:col-span-2">
                                        <button
                                            type="submit"
                                            disabled={form.processing}
                                            className={`${primaryButtonClass} min-h-12`}
                                        >
                                            {t('admin.donations.save')}
                                        </button>
                                    </div>
                                    <section
                                        aria-labelledby="donations-links-title"
                                        className={sectionClass}
                                    >
                                        <h2
                                            id="donations-links-title"
                                            className={sectionTitleClass}
                                        >
                                            {t('admin.donations.links')}
                                        </h2>
                                        {methods.map((method) => {
                                            const id = `donation-url-${method.platform}`;
                                            const error =
                                                errors[
                                                    `urls.${method.platform}`
                                                ];

                                            return (
                                                <div
                                                    key={method.platform}
                                                    className="grid gap-1.5"
                                                >
                                                    <label
                                                        htmlFor={id}
                                                        className="text-sm font-semibold"
                                                    >
                                                        {t(
                                                            `donate.platform.${method.platform}`,
                                                        )}
                                                    </label>
                                                    <input
                                                        id={id}
                                                        type="url"
                                                        inputMode="url"
                                                        autoComplete="off"
                                                        spellCheck={false}
                                                        value={
                                                            form.data.urls[
                                                                method.platform
                                                            ]
                                                        }
                                                        aria-invalid={Boolean(
                                                            error,
                                                        )}
                                                        aria-describedby={`${id}-help${error ? ` ${id}-error` : ''}`}
                                                        onChange={(event) => {
                                                            form.clearErrors(
                                                                `urls.${method.platform}`,
                                                            );
                                                            form.setData(
                                                                'urls',
                                                                {
                                                                    ...form.data
                                                                        .urls,
                                                                    [method.platform]:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            );
                                                        }}
                                                        className={inputClass}
                                                    />
                                                    <p
                                                        id={`${id}-help`}
                                                        className="text-[13px] text-muted"
                                                    >
                                                        {t(
                                                            'admin.donations.urlHelp',
                                                            {
                                                                domain: method.domain,
                                                            },
                                                        )}
                                                    </p>
                                                    {error ? (
                                                        <p
                                                            id={`${id}-error`}
                                                            role="alert"
                                                            className={
                                                                fieldErrorClass
                                                            }
                                                        >
                                                            {error}
                                                        </p>
                                                    ) : null}
                                                </div>
                                            );
                                        })}
                                    </section>
                                    <section
                                        aria-labelledby="donations-month-title"
                                        className={sectionClass}
                                    >
                                        <h2
                                            id="donations-month-title"
                                            className={sectionTitleClass}
                                        >
                                            {t('admin.donations.month', {
                                                month: monthLabel(
                                                    period.month,
                                                    locale,
                                                ),
                                            })}
                                        </h2>
                                        {amountFields.map(
                                            ({ id, field, label }) => {
                                                const inputId = `donation-${id}`;
                                                const error = errors[field];

                                                return (
                                                    <div
                                                        key={id}
                                                        className="grid gap-1.5"
                                                    >
                                                        <label
                                                            htmlFor={inputId}
                                                            className="text-sm font-semibold"
                                                        >
                                                            {label}
                                                        </label>
                                                        <div className="flex items-center gap-2">
                                                            <input
                                                                id={inputId}
                                                                type="text"
                                                                inputMode="decimal"
                                                                autoComplete="off"
                                                                value={
                                                                    form.data[
                                                                        id
                                                                    ]
                                                                }
                                                                aria-invalid={Boolean(
                                                                    error,
                                                                )}
                                                                aria-describedby={
                                                                    error
                                                                        ? `${inputId}-error`
                                                                        : undefined
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) => {
                                                                    form.clearErrors();
                                                                    setAmountErrors(
                                                                        (
                                                                            current,
                                                                        ) => ({
                                                                            ...current,
                                                                            [field]:
                                                                                undefined,
                                                                        }),
                                                                    );
                                                                    form.setData(
                                                                        id,
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    );
                                                                }}
                                                                className={`${inputClass} mono tabular-nums`}
                                                            />
                                                            <span
                                                                aria-hidden="true"
                                                                className="text-muted"
                                                            >
                                                                €
                                                            </span>
                                                        </div>
                                                        {error ? (
                                                            <p
                                                                id={`${inputId}-error`}
                                                                role="alert"
                                                                className={
                                                                    fieldErrorClass
                                                                }
                                                            >
                                                                {error}
                                                            </p>
                                                        ) : null}
                                                    </div>
                                                );
                                            },
                                        )}
                                        <p className="text-[13px] text-muted">
                                            {t('admin.donations.amountHelp')}
                                        </p>
                                    </section>
                                </form>
                            ),
                        },
                        {
                            id: 'history',
                            label: t('admin.donations.tabHistory'),
                            panel: (
                                <section
                                    aria-labelledby="donations-history-title"
                                    className="grid gap-4"
                                >
                                    <h2
                                        id="donations-history-title"
                                        className={sectionTitleClass}
                                    >
                                        {t('admin.donations.history')}
                                    </h2>
                                    <div className="overflow-x-auto rounded-md border border-border">
                                        <table className="w-full border-collapse text-left text-sm">
                                            <thead>
                                                <tr className="border-b border-border bg-surface text-muted">
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2.5 font-semibold"
                                                    >
                                                        {t(
                                                            'admin.donations.colMonth',
                                                        )}
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2.5 text-right font-semibold"
                                                    >
                                                        {t(
                                                            'admin.donations.colGoal',
                                                        )}
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2.5 text-right font-semibold"
                                                    >
                                                        {t(
                                                            'admin.donations.colCovered',
                                                        )}
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2.5 text-right font-semibold"
                                                    >
                                                        {t(
                                                            'admin.donations.colPercent',
                                                        )}
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2.5 font-semibold"
                                                    >
                                                        {t(
                                                            'admin.donations.colUpdated',
                                                        )}
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {history.map((row) => (
                                                    <tr
                                                        key={row.id}
                                                        className="border-b border-border last:border-b-0"
                                                    >
                                                        <th
                                                            scope="row"
                                                            className="px-3 py-2.5 font-semibold capitalize"
                                                        >
                                                            {monthLabel(
                                                                row.month,
                                                                locale,
                                                            )}
                                                        </th>
                                                        <td className="px-3 py-2.5 text-right mono tabular-nums">
                                                            {formatEuros(
                                                                row.goal_cents,
                                                                locale,
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2.5 text-right mono tabular-nums">
                                                            {formatEuros(
                                                                row.covered_cents,
                                                                locale,
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2.5 text-right mono tabular-nums">
                                                            {row.percent} %
                                                        </td>
                                                        <td className="px-3 py-2.5 text-muted">
                                                            {row.updated_at ===
                                                            null
                                                                ? '—'
                                                                : formatDateTime(
                                                                      new Date(
                                                                          row.updated_at,
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
                                </section>
                            ),
                        },
                    ]}
                />
            </div>
        </>
    );
}
