import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { NotSupportedError, UserCancelledError } from '@laravel/passkeys';
import type { PasskeyError } from '@laravel/passkeys';
import { usePasskeyVerify } from '@laravel/passkeys/react';
import { KeyRound, Mail } from 'lucide-react';
import {
    fieldErrorClass,
    secondaryButtonClass,
    statusClass,
} from '@/components/account-styles';
import { useT } from '@/hooks/use-t';
import type { Translate } from '@/hooks/use-t';
import { show as accountShow } from '@/routes/account';
import { link as confirmLink } from '@/routes/account/confirm';
import { redirect as googleRedirect } from '@/routes/auth/google';
import { redirect as steamRedirect } from '@/routes/auth/steam';
import { confirm, confirmOptions } from '@/routes/passkey';

type ConfirmMethods = {
    passkey: boolean;
    steam: boolean;
    google: boolean;
    email: boolean;
};

const methodClass =
    'inline-flex min-h-13 w-full items-center justify-center gap-2 rounded-sm border border-border-strong px-6.5 text-base leading-none font-semibold text-fg transition-colors duration-150 ease-out hover:bg-surface-2 disabled:opacity-55';

function passkeyErrorMessage(error: PasskeyError, t: Translate): string {
    if (error instanceof UserCancelledError) {
        return t('account.confirm.passkeyCancelled');
    }

    if (error instanceof NotSupportedError) {
        return t('account.passkeys.unsupported');
    }

    return t('account.confirm.passkeyError');
}

function PasskeyConfirmButton() {
    const t = useT();
    const { verify, isLoading, errorInstance, isSupported } = usePasskeyVerify({
        routes: { options: confirmOptions().url, submit: confirm().url },
        onSuccess: (response) => {
            router.visit(response.redirect ?? accountShow().url);
        },
    });

    const messageId = 'passkey-confirm-message';
    const message = !isSupported
        ? t('account.passkeys.unsupported')
        : errorInstance
          ? passkeyErrorMessage(errorInstance, t)
          : null;

    return (
        <div className="grid gap-2">
            <button
                type="button"
                onClick={() => void verify()}
                disabled={!isSupported || isLoading}
                aria-describedby={message ? messageId : undefined}
                className={methodClass}
            >
                <KeyRound className="size-[18px]" aria-hidden="true" />
                {isLoading
                    ? t('account.confirm.passkeyLoading')
                    : t('account.confirm.passkey')}
            </button>
            {message ? (
                <p
                    id={messageId}
                    role={errorInstance ? 'alert' : undefined}
                    className={
                        errorInstance
                            ? fieldErrorClass
                            : 'text-[13px] text-muted'
                    }
                >
                    {message}
                </p>
            ) : null}
        </div>
    );
}

export default function AccountConfirm({
    methods,
    minutes,
}: {
    methods: ConfirmMethods;
    minutes: number;
}) {
    const t = useT();
    const flash = usePage().flash as { linkSent?: string };

    return (
        <>
            <Head title={t('account.confirm.title')} />
            <div className="mx-auto mt-6 grid max-w-[480px] gap-7">
                <section
                    aria-labelledby="confirm-title"
                    className="grid gap-5.5 rounded-lg border border-border bg-surface px-5.5 py-7"
                >
                    <h1
                        id="confirm-title"
                        className="font-display text-[clamp(1.75rem,6vw,2.25rem)] leading-[1.1] font-bold tracking-[-0.03em]"
                    >
                        {t('account.confirm.title')}
                    </h1>
                    <p className="text-muted">
                        {t('account.confirm.body', { minutes })}
                    </p>
                    <div className="grid gap-3">
                        {methods.passkey ? <PasskeyConfirmButton /> : null}
                        {methods.steam ? (
                            <a
                                href={steamRedirect().url}
                                className={methodClass}
                            >
                                {t('account.confirm.steam')}
                            </a>
                        ) : null}
                        {methods.google ? (
                            <a
                                href={googleRedirect().url}
                                className={methodClass}
                            >
                                {t('account.confirm.google')}
                            </a>
                        ) : null}
                        {methods.email ? (
                            <Form action={confirmLink()} className="grid">
                                {({ processing }) => (
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className={methodClass}
                                    >
                                        <Mail
                                            className="size-[18px]"
                                            aria-hidden="true"
                                        />
                                        {t('account.confirm.email')}
                                    </button>
                                )}
                            </Form>
                        ) : null}
                    </div>
                    {flash.linkSent ? (
                        <p role="status" className={statusClass}>
                            {flash.linkSent}
                        </p>
                    ) : null}
                    <Link
                        href={accountShow().url}
                        className={`${secondaryButtonClass} justify-self-start`}
                    >
                        {t('account.confirm.back')}
                    </Link>
                </section>
            </div>
        </>
    );
}
