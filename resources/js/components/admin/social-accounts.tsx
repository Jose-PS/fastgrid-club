import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    fieldErrorClass,
    inputClass,
    primaryButtonClass,
    secondaryButtonClass,
    sectionClass,
    sectionTitleClass,
} from '@/components/account-styles';
import { Badge } from '@/components/ui/badge';
import { useT } from '@/hooks/use-t';
import { showToast } from '@/lib/toast';
import { test as testConnection, update } from '@/routes/admin/social/accounts';

export type SocialAccount = {
    channel: 'discord' | 'bluesky' | 'instagram';
    enabled: boolean;
    values: Record<string, string>;
    secrets: Record<string, boolean>;
};

type AccountForm = {
    enabled: boolean;
    values: Record<string, string>;
    secrets: Record<string, string>;
    clear: string[];
};

const SECRET_MASK = '••••••••••••';

function AccountCard({ account }: { account: SocialAccount }) {
    const t = useT();
    const [testing, setTesting] = useState(false);
    const form = useForm<AccountForm>({
        enabled: account.enabled,
        values: account.values,
        secrets: Object.fromEntries(
            Object.keys(account.secrets).map((field) => [field, '']),
        ),
        clear: [],
    });
    const testHintId = `${account.channel}-test-hint`;
    const errors = form.errors as Record<string, string | undefined>;

    const save = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        form.clearErrors();
        form.transform((data) => ({
            enabled: data.enabled,
            ...data.values,
            ...Object.fromEntries(
                Object.entries(data.secrets).filter(
                    ([, value]) => value.trim() !== '',
                ),
            ),
            clear: data.clear,
        }));
        form.put(update(account.channel).url, {
            preserveScroll: true,
            onSuccess: () => {
                const saved: AccountForm = {
                    enabled: form.data.enabled,
                    values: form.data.values,
                    secrets: Object.fromEntries(
                        Object.keys(form.data.secrets).map((field) => [
                            field,
                            '',
                        ]),
                    ),
                    clear: [],
                };

                form.setDefaults(saved);
                form.setData(saved);
            },
        });
    };

    const test = (): void => {
        router.post(
            testConnection(account.channel).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setTesting(true),
                onFinish: () => setTesting(false),
                onError: () =>
                    showToast({
                        type: 'error',
                        message: t('admin.social.accounts.testUnexpected'),
                    }),
            },
        );
    };

    const toggleClear = (field: string): void =>
        form.setData(
            'clear',
            form.data.clear.includes(field)
                ? form.data.clear.filter((item) => item !== field)
                : [...form.data.clear, field],
        );

    return (
        <li className={sectionClass}>
            <form onSubmit={save} noValidate className="grid gap-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h3 className={sectionTitleClass}>
                        {t(`admin.social.channel.${account.channel}`)}
                    </h3>
                    <Badge variant="secondary">
                        {form.data.enabled
                            ? t('admin.social.accounts.active')
                            : t('admin.social.accounts.paused')}
                    </Badge>
                </div>
                <div className="flex items-start gap-3">
                    <input
                        id={`${account.channel}-enabled`}
                        type="checkbox"
                        checked={form.data.enabled}
                        onChange={(event) =>
                            form.setData('enabled', event.target.checked)
                        }
                        className="mt-0.5 size-5 flex-none accent-primary"
                    />
                    <div className="grid gap-1">
                        <label
                            htmlFor={`${account.channel}-enabled`}
                            className="text-sm font-semibold"
                        >
                            {t('admin.social.accounts.enabled')}
                        </label>
                        <p className="text-[13px] text-muted">
                            {t('admin.social.accounts.enabledHint')}
                        </p>
                    </div>
                </div>
                {Object.keys(account.values).map((field) => {
                    const id = `${account.channel}-${field}`;

                    return (
                        <div key={field} className="grid gap-1.5">
                            <label
                                htmlFor={id}
                                className="text-sm font-semibold"
                            >
                                {t(`admin.social.accounts.field.${field}`)}
                            </label>
                            <input
                                id={id}
                                type="text"
                                autoComplete="off"
                                value={form.data.values[field] ?? ''}
                                onChange={(event) =>
                                    form.setData('values', {
                                        ...form.data.values,
                                        [field]: event.target.value,
                                    })
                                }
                                aria-invalid={errors[field] ? true : undefined}
                                className={inputClass}
                            />
                            {errors[field] ? (
                                <p role="alert" className={fieldErrorClass}>
                                    {errors[field]}
                                </p>
                            ) : null}
                        </div>
                    );
                })}
                {Object.entries(account.secrets).map(([field, configured]) => {
                    const id = `${account.channel}-${field}`;
                    const removing = form.data.clear.includes(field);

                    return (
                        <div key={field} className="grid gap-1.5">
                            <label
                                htmlFor={id}
                                className="text-sm font-semibold"
                            >
                                {t(`admin.social.accounts.field.${field}`)}
                            </label>
                            <span id={`${id}-status`} className="sr-only">
                                {configured
                                    ? t('admin.social.accounts.secretSet')
                                    : t('admin.social.accounts.secretUnset')}
                            </span>
                            <input
                                id={id}
                                type="password"
                                autoComplete="new-password"
                                value={form.data.secrets[field] ?? ''}
                                onChange={(event) =>
                                    form.setData('secrets', {
                                        ...form.data.secrets,
                                        [field]: event.target.value,
                                    })
                                }
                                placeholder={
                                    configured && !removing
                                        ? SECRET_MASK
                                        : t(
                                              'admin.social.accounts.secretNewPlaceholder',
                                          )
                                }
                                aria-describedby={`${id}-status`}
                                aria-invalid={errors[field] ? true : undefined}
                                className={inputClass}
                            />
                            {errors[field] ? (
                                <p role="alert" className={fieldErrorClass}>
                                    {errors[field]}
                                </p>
                            ) : null}
                            {configured ? (
                                <div className="flex flex-wrap items-center gap-3 text-[13px] text-muted">
                                    {removing ? (
                                        <span>
                                            {t(
                                                'admin.social.accounts.removePending',
                                            )}
                                        </span>
                                    ) : null}
                                    <button
                                        type="button"
                                        onClick={() => toggleClear(field)}
                                        className="min-h-11 font-semibold text-fg underline"
                                    >
                                        {removing
                                            ? t(
                                                  'admin.social.accounts.undoRemove',
                                              )
                                            : t('admin.social.accounts.remove')}
                                    </button>
                                </div>
                            ) : null}
                        </div>
                    );
                })}
                <div className="flex flex-wrap gap-3">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className={primaryButtonClass}
                    >
                        {t('admin.social.accounts.save')}
                    </button>
                    <button
                        type="button"
                        disabled={testing || form.processing || form.isDirty}
                        onClick={test}
                        aria-describedby={form.isDirty ? testHintId : undefined}
                        className={secondaryButtonClass}
                    >
                        {testing
                            ? t('admin.social.accounts.testing')
                            : t('admin.social.accounts.test')}
                    </button>
                    {form.isDirty ? (
                        <p
                            id={testHintId}
                            className="self-center text-[13px] text-muted"
                        >
                            {t('admin.social.accounts.saveBeforeTest')}
                        </p>
                    ) : null}
                </div>
            </form>
        </li>
    );
}

export function SocialAccounts({ accounts }: { accounts: SocialAccount[] }) {
    const t = useT();

    return (
        <div className="grid max-w-4xl gap-4">
            <p className="text-muted">{t('admin.social.accounts.intro')}</p>
            <ul className="grid gap-4">
                {accounts.map((account) => (
                    <AccountCard key={account.channel} account={account} />
                ))}
            </ul>
        </div>
    );
}
