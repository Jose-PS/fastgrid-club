import { Form, router } from '@inertiajs/react';
import { LogOut, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    dangerButtonClass,
    fieldErrorClass,
    inputClass,
    secondaryButtonClass,
    sectionTitleClass,
} from '@/components/account-styles';
import { useT } from '@/hooks/use-t';
import { destroy as destroyAccount } from '@/routes/account';
import { destroy as destroySessions } from '@/routes/account/sessions';

function DeleteAccountForm({ onCancel }: { onCancel: () => void }) {
    const t = useT();
    const word = t('account.danger.deleteWord');

    return (
        <Form
            action={destroyAccount()}
            options={{ preserveScroll: true }}
            className="grid gap-3"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-1.5">
                        <label
                            htmlFor="delete-confirmation"
                            className="text-sm font-semibold"
                        >
                            {t('account.danger.confirmationLabel', { word })}
                        </label>
                        <input
                            id="delete-confirmation"
                            name="confirmation"
                            autoComplete="off"
                            autoCapitalize="characters"
                            spellCheck={false}
                            required
                            autoFocus
                            aria-invalid={
                                errors.confirmation ? true : undefined
                            }
                            aria-describedby={
                                errors.confirmation
                                    ? 'delete-confirmation-error'
                                    : undefined
                            }
                            className={inputClass}
                        />
                        {errors.confirmation ? (
                            <p
                                id="delete-confirmation-error"
                                role="alert"
                                className={fieldErrorClass}
                            >
                                {errors.confirmation}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex items-start gap-3">
                        <input
                            id="delete-hide-laps"
                            name="hide_laps"
                            type="checkbox"
                            value="1"
                            className="mt-0.5 size-5 flex-none accent-primary"
                        />
                        <label
                            htmlFor="delete-hide-laps"
                            className="text-sm font-semibold"
                        >
                            {t('account.danger.hideLaps')}
                        </label>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="submit"
                            disabled={processing}
                            className={dangerButtonClass}
                        >
                            <Trash2 className="size-4" aria-hidden="true" />
                            {t('account.danger.deleteAction')}
                        </button>
                        <button
                            type="button"
                            onClick={onCancel}
                            className={secondaryButtonClass}
                        >
                            {t('account.danger.cancel')}
                        </button>
                    </div>
                </>
            )}
        </Form>
    );
}

export function DangerZone() {
    const t = useT();
    const [isDeleting, setIsDeleting] = useState(false);

    const onLogoutOthers = (): void => {
        if (!window.confirm(t('account.danger.sessionsConfirm'))) {
            return;
        }

        router.post(destroySessions().url, {}, { preserveScroll: true });
    };

    return (
        <section
            aria-labelledby="danger-title"
            className="grid gap-5 rounded-lg border border-err/45 bg-surface px-5.5 py-6"
        >
            <h2 id="danger-title" className={sectionTitleClass}>
                {t('account.danger.title')}
            </h2>
            <div className="grid gap-3">
                <div>
                    <h3 className="font-semibold">
                        {t('account.danger.sessionsTitle')}
                    </h3>
                    <p className="mt-1 text-sm text-muted">
                        {t('account.danger.sessionsBody')}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={onLogoutOthers}
                    className={`${secondaryButtonClass} justify-self-start`}
                >
                    <LogOut className="size-4" aria-hidden="true" />
                    {t('account.danger.sessionsAction')}
                </button>
            </div>
            <div className="grid gap-3 border-t border-border pt-5">
                <div>
                    <h3 className="font-semibold text-err">
                        {t('account.danger.deleteTitle')}
                    </h3>
                    <p className="mt-1 text-sm text-muted">
                        {t('account.danger.deleteBody')}
                    </p>
                </div>
                {isDeleting ? (
                    <DeleteAccountForm onCancel={() => setIsDeleting(false)} />
                ) : (
                    <button
                        type="button"
                        onClick={() => setIsDeleting(true)}
                        className={`${secondaryButtonClass} justify-self-start text-err`}
                    >
                        <Trash2 className="size-4" aria-hidden="true" />
                        {t('account.danger.open')}
                    </button>
                )}
            </div>
        </section>
    );
}
