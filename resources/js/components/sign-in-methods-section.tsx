import { Form, router, usePage } from '@inertiajs/react';
import { Link2, Mail, Unlink } from 'lucide-react';
import { useState } from 'react';
import {
    alertClass,
    fieldErrorClass,
    inputClass,
    primaryButtonClass,
    secondaryButtonClass,
    sectionClass,
    sectionTitleClass,
    statusClass,
} from '@/components/account-styles';
import { useT } from '@/hooks/use-t';
import { formatDate, formatTime } from '@/lib/datetime';
import { update as updateEmail } from '@/routes/account/email';
import { destroy as unlink } from '@/routes/account/identities';
import { link as googleLink } from '@/routes/account/identities/google';
import { link as steamLink } from '@/routes/account/identities/steam';

type ProviderMethod = {
    label: string;
    linkedAt: string;
    canUnlink: boolean;
};

export type AccountMethods = {
    steam: ProviderMethod | null;
    google: ProviderMethod | null;
    email: { address: string; verifiedAt: string | null } | null;
};

type Provider = 'steam' | 'google';

const EMAIL_MAX_LENGTH = 254;

function useLongDate(): (iso: string) => string {
    const { locale } = usePage<{ locale: string }>().props;

    return (iso) =>
        formatDate(new Date(iso), locale, {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
}

function ProviderRow({
    provider,
    method,
}: {
    provider: Provider;
    method: ProviderMethod | null;
}) {
    const t = useT();
    const longDate = useLongDate();
    const linkUrl = provider === 'steam' ? steamLink().url : googleLink().url;
    const onlyMethodId = `${provider}-only-method`;

    const onUnlink = (): void => {
        if (!window.confirm(t(`account.methods.unlinkConfirm.${provider}`))) {
            return;
        }

        router.delete(unlink(provider).url, { preserveScroll: true });
    };

    return (
        <li className="flex flex-wrap items-center justify-between gap-3 py-4">
            <div className="grid gap-1">
                <p className="font-semibold">
                    {t(`account.methods.${provider}`)}
                </p>
                <p className="text-[13px] wrap-anywhere text-muted">
                    {method
                        ? `${method.label} · ${t('account.methods.linkedAt', {
                              date: longDate(method.linkedAt),
                          })}`
                        : t('account.methods.notLinked')}
                </p>
                {method && !method.canUnlink ? (
                    <p id={onlyMethodId} className="text-[13px] text-muted">
                        {t('account.methods.onlyMethod')}
                    </p>
                ) : null}
            </div>
            {method ? (
                <button
                    type="button"
                    onClick={onUnlink}
                    disabled={!method.canUnlink}
                    aria-describedby={
                        method.canUnlink ? undefined : onlyMethodId
                    }
                    className={secondaryButtonClass}
                >
                    <Unlink className="size-4" aria-hidden="true" />
                    {t('account.methods.unlink')}
                </button>
            ) : (
                <a href={linkUrl} className={secondaryButtonClass}>
                    <Link2 className="size-4" aria-hidden="true" />
                    {t('account.methods.link')}
                </a>
            )}
        </li>
    );
}

function EmailRow({ email }: { email: AccountMethods['email'] }) {
    const t = useT();
    const longDate = useLongDate();
    const [isEditing, setIsEditing] = useState(false);
    const actionLabel = email
        ? t('account.email.change')
        : t('account.email.add');

    const status = email
        ? email.verifiedAt
            ? t('account.methods.verifiedAt', {
                  date: longDate(email.verifiedAt),
              })
            : t('account.methods.unverified')
        : t('account.methods.noEmail');

    return (
        <li className="grid gap-3 py-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="grid gap-1">
                    <p className="font-semibold">
                        {t('account.methods.email')}
                    </p>
                    <p className="text-[13px] wrap-anywhere text-muted">
                        {email ? `${email.address} · ${status}` : status}
                    </p>
                </div>
                {isEditing ? null : (
                    <button
                        type="button"
                        onClick={() => setIsEditing(true)}
                        className={secondaryButtonClass}
                    >
                        <Mail className="size-4" aria-hidden="true" />
                        {actionLabel}
                    </button>
                )}
            </div>
            {isEditing ? (
                <Form
                    action={updateEmail()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setIsEditing(false)}
                    className="grid gap-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-1.5">
                                <label
                                    htmlFor="account-email"
                                    className="text-sm font-semibold"
                                >
                                    {t('account.email.label')}
                                </label>
                                <input
                                    id="account-email"
                                    name="email"
                                    type="email"
                                    inputMode="email"
                                    autoComplete="email"
                                    maxLength={EMAIL_MAX_LENGTH}
                                    required
                                    autoFocus
                                    aria-invalid={
                                        errors.email ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.email
                                            ? 'account-email-hint account-email-error'
                                            : 'account-email-hint'
                                    }
                                    className={inputClass}
                                />
                                <p
                                    id="account-email-hint"
                                    className="text-[13px] text-muted"
                                >
                                    {t('account.email.hint')}
                                </p>
                                {errors.email ? (
                                    <p
                                        id="account-email-error"
                                        role="alert"
                                        className={fieldErrorClass}
                                    >
                                        {errors.email}
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className={primaryButtonClass}
                                >
                                    {t('account.email.submit')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setIsEditing(false)}
                                    className={secondaryButtonClass}
                                >
                                    {t('account.email.cancel')}
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            ) : null}
        </li>
    );
}

export function SignInMethodsSection({
    methods,
    confirmedUntil,
}: {
    methods: AccountMethods;
    confirmedUntil: string | null;
}) {
    const t = useT();
    const { errors, locale } = usePage<{
        errors: Record<string, string>;
        locale: string;
    }>().props;
    const flash = usePage().flash as { emailSent?: string };

    return (
        <section aria-labelledby="methods-title" className={sectionClass}>
            <div>
                <h2 id="methods-title" className={sectionTitleClass}>
                    {t('account.methods.title')}
                </h2>
                <p className="mt-1 text-sm text-muted">
                    {t('account.methods.intro')}
                </p>
                <p className="mt-1 text-[13px] text-muted">
                    {confirmedUntil
                        ? t('account.methods.confirmedUntil', {
                              time: formatTime(
                                  new Date(confirmedUntil),
                                  locale,
                              ),
                          })
                        : t('account.methods.confirmNote')}
                </p>
            </div>
            {errors.identity ? (
                <p role="alert" className={alertClass}>
                    {errors.identity}
                </p>
            ) : null}
            {flash.emailSent ? (
                <p role="status" className={statusClass}>
                    {flash.emailSent}
                </p>
            ) : null}
            <ul className="divide-y divide-border">
                <ProviderRow provider="steam" method={methods.steam} />
                <ProviderRow provider="google" method={methods.google} />
                <EmailRow email={methods.email} />
            </ul>
        </section>
    );
}
