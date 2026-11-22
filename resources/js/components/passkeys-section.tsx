import { Form, router, usePage } from '@inertiajs/react';
import {
    NotSupportedError,
    PasskeyExistsError,
    UserCancelledError,
} from '@laravel/passkeys';
import type { PasskeyError } from '@laravel/passkeys';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { KeyRound, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { useT } from '@/hooks/use-t';
import type { Translate } from '@/hooks/use-t';
import { formatDate } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { destroy, registrationOptions, store, update } from '@/routes/passkey';

export type AccountPasskey = {
    id: number;
    name: string;
    createdAt: string | null;
    lastUsedAt: string | null;
};

const ALIAS_MAX_LENGTH = 40;

const inputClass =
    'min-h-11 w-full rounded-sm border border-border-strong bg-surface px-3 text-fg';

const primaryButtonClass =
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border border-primary bg-primary px-4 text-sm font-semibold text-on-primary transition-[filter] duration-150 ease-out hover:brightness-110 disabled:opacity-55';

const secondaryButtonClass =
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border border-border-strong px-3 text-sm font-semibold text-fg transition-colors duration-150 ease-out hover:bg-surface-2 disabled:opacity-55';

function suggestedAlias(t: Translate): string {
    const isMobile = /Android|iPhone|iPad|Mobi/i.test(navigator.userAgent);

    return t(
        isMobile
            ? 'account.passkeys.suggestPhone'
            : 'account.passkeys.suggestLaptop',
    );
}

function registerErrorMessage(error: PasskeyError, t: Translate): string {
    if (error instanceof UserCancelledError) {
        return t('account.passkeys.cancelled');
    }

    if (error instanceof PasskeyExistsError) {
        return t('account.passkeys.exists');
    }

    if (error instanceof NotSupportedError) {
        return t('account.passkeys.unsupported');
    }

    return t('account.passkeys.addError');
}

function AddPasskey() {
    const t = useT();
    const [alias, setAlias] = useState<string | null>(null);
    const { register, isLoading, errorInstance, isSupported } =
        usePasskeyRegister({
            routes: { options: registrationOptions().url, submit: store().url },
            onSuccess: () => {
                setAlias(null);
                router.reload({ only: ['passkeys'] });
            },
        });

    const onSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (alias !== null && alias.trim() !== '') {
            void register(alias.trim());
        }
    };

    if (!isSupported) {
        return (
            <p className="text-sm text-muted">
                {t('account.passkeys.unsupported')}
            </p>
        );
    }

    if (alias === null) {
        return (
            <div className="grid gap-2">
                <button
                    type="button"
                    onClick={() => setAlias(suggestedAlias(t))}
                    className={cn(primaryButtonClass, 'justify-self-start')}
                >
                    <Plus className="size-[18px]" aria-hidden="true" />
                    {t('account.passkeys.add')}
                </button>
                {errorInstance ? (
                    <p role="alert" className="text-[13px] text-err">
                        {registerErrorMessage(errorInstance, t)}
                    </p>
                ) : null}
            </div>
        );
    }

    return (
        <form onSubmit={onSubmit} className="grid gap-3">
            <div className="grid gap-1.5">
                <label
                    htmlFor="passkey-alias"
                    className="text-sm font-semibold"
                >
                    {t('account.passkeys.aliasLabel')}
                </label>
                <input
                    id="passkey-alias"
                    value={alias}
                    onChange={(event) => setAlias(event.target.value)}
                    maxLength={ALIAS_MAX_LENGTH}
                    required
                    autoFocus
                    aria-describedby="passkey-alias-hint"
                    className={inputClass}
                />
                <p id="passkey-alias-hint" className="text-[13px] text-muted">
                    {t('account.passkeys.aliasHint')}
                </p>
            </div>
            {errorInstance ? (
                <p role="alert" className="text-[13px] text-err">
                    {registerErrorMessage(errorInstance, t)}
                </p>
            ) : null}
            <div className="flex flex-wrap gap-2">
                <button
                    type="submit"
                    disabled={isLoading || alias.trim() === ''}
                    className={primaryButtonClass}
                >
                    <KeyRound className="size-[18px]" aria-hidden="true" />
                    {isLoading
                        ? t('account.passkeys.adding')
                        : t('account.passkeys.add')}
                </button>
                <button
                    type="button"
                    disabled={isLoading}
                    onClick={() => setAlias(null)}
                    className={secondaryButtonClass}
                >
                    {t('account.passkeys.cancel')}
                </button>
            </div>
        </form>
    );
}

function PasskeyRow({ passkey }: { passkey: AccountPasskey }) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const [isRenaming, setIsRenaming] = useState(false);

    const formatted = (iso: string): string =>
        formatDate(new Date(iso), locale, {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });

    const onDelete = (): void => {
        if (
            !window.confirm(
                t('account.passkeys.deleteConfirm', { name: passkey.name }),
            )
        ) {
            return;
        }

        router.delete(destroy(passkey.id).url, { preserveScroll: true });
    };

    if (isRenaming) {
        return (
            <li className="py-4">
                <Form
                    action={update(passkey.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setIsRenaming(false)}
                    className="grid gap-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-1.5">
                                <label
                                    htmlFor={`passkey-name-${passkey.id}`}
                                    className="text-sm font-semibold"
                                >
                                    {t('account.passkeys.aliasLabel')}
                                </label>
                                <input
                                    id={`passkey-name-${passkey.id}`}
                                    name="name"
                                    defaultValue={passkey.name}
                                    maxLength={ALIAS_MAX_LENGTH}
                                    required
                                    autoFocus
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.name
                                            ? `passkey-name-${passkey.id}-error`
                                            : undefined
                                    }
                                    className={inputClass}
                                />
                                {errors.name ? (
                                    <p
                                        id={`passkey-name-${passkey.id}-error`}
                                        role="alert"
                                        className="text-[13px] text-err"
                                    >
                                        {errors.name}
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className={primaryButtonClass}
                                >
                                    {t('account.passkeys.save')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setIsRenaming(false)}
                                    className={secondaryButtonClass}
                                >
                                    {t('account.passkeys.cancel')}
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </li>
        );
    }

    return (
        <li className="flex flex-wrap items-center justify-between gap-3 py-4">
            <div className="grid gap-1">
                <p className="font-semibold">{passkey.name}</p>
                <p className="text-[13px] text-muted">
                    {passkey.createdAt
                        ? t('account.passkeys.createdAt', {
                              date: formatted(passkey.createdAt),
                          })
                        : null}
                    {' · '}
                    {passkey.lastUsedAt
                        ? t('account.passkeys.lastUsedAt', {
                              date: formatted(passkey.lastUsedAt),
                          })
                        : t('account.passkeys.neverUsed')}
                </p>
            </div>
            <div className="flex gap-2">
                <button
                    type="button"
                    onClick={() => setIsRenaming(true)}
                    className={secondaryButtonClass}
                >
                    <Pencil className="size-4" aria-hidden="true" />
                    {t('account.passkeys.rename')}
                </button>
                <button
                    type="button"
                    onClick={onDelete}
                    className={secondaryButtonClass}
                >
                    <Trash2 className="size-4" aria-hidden="true" />
                    {t('account.passkeys.delete')}
                </button>
            </div>
        </li>
    );
}

export function PasskeysSection({ passkeys }: { passkeys: AccountPasskey[] }) {
    const t = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <section
            aria-labelledby="passkeys-title"
            className="grid gap-4 rounded-lg border border-border bg-surface px-5.5 py-6"
        >
            <div>
                <h2
                    id="passkeys-title"
                    className="font-display text-xl leading-tight font-bold tracking-[-0.01em]"
                >
                    {t('account.passkeys.title')}
                </h2>
                <p className="mt-1 text-sm text-muted">
                    {t('account.passkeys.intro')}
                </p>
            </div>
            {errors.passkey ? (
                <p
                    role="alert"
                    className="rounded-md border border-err/45 px-3.5 py-3 font-medium text-err"
                >
                    {errors.passkey}
                </p>
            ) : null}
            {passkeys.length > 0 ? (
                <ul className="divide-y divide-border">
                    {passkeys.map((passkey) => (
                        <PasskeyRow key={passkey.id} passkey={passkey} />
                    ))}
                </ul>
            ) : (
                <p className="text-sm text-muted">
                    {t('account.passkeys.empty')}
                </p>
            )}
            <AddPasskey />
        </section>
    );
}
