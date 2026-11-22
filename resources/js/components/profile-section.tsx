import { Form } from '@inertiajs/react';
import {
    fieldErrorClass,
    inputClass,
    primaryButtonClass,
    sectionClass,
    sectionTitleClass,
} from '@/components/account-styles';
import { useT } from '@/hooks/use-t';
import { update } from '@/routes/account/profile';

export type AccountProfile = {
    name: string;
    locale: string;
    showInRankings: boolean;
};

const NAME_MIN_LENGTH = 3;
const NAME_MAX_LENGTH = 32;

export function ProfileSection({
    profile,
    locales,
}: {
    profile: AccountProfile;
    locales: string[];
}) {
    const t = useT();

    return (
        <section aria-labelledby="profile-title" className={sectionClass}>
            <h2 id="profile-title" className={sectionTitleClass}>
                {t('account.profile.title')}
            </h2>
            <Form
                action={update()}
                options={{ preserveScroll: true }}
                className="grid gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-1.5">
                            <label
                                htmlFor="profile-name"
                                className="text-sm font-semibold"
                            >
                                {t('account.profile.name')}
                            </label>
                            <input
                                id="profile-name"
                                name="name"
                                defaultValue={profile.name}
                                minLength={NAME_MIN_LENGTH}
                                maxLength={NAME_MAX_LENGTH}
                                autoComplete="nickname"
                                required
                                aria-invalid={errors.name ? true : undefined}
                                aria-describedby={
                                    errors.name
                                        ? 'profile-name-hint profile-name-error'
                                        : 'profile-name-hint'
                                }
                                className={inputClass}
                            />
                            <p
                                id="profile-name-hint"
                                className="text-[13px] text-muted"
                            >
                                {t('account.profile.nameHint')}
                            </p>
                            {errors.name ? (
                                <p
                                    id="profile-name-error"
                                    role="alert"
                                    className={fieldErrorClass}
                                >
                                    {errors.name}
                                </p>
                            ) : null}
                        </div>
                        <div className="grid gap-1.5">
                            <label
                                htmlFor="profile-locale"
                                className="text-sm font-semibold"
                            >
                                {t('account.profile.locale')}
                            </label>
                            <select
                                id="profile-locale"
                                name="locale"
                                defaultValue={profile.locale}
                                aria-invalid={errors.locale ? true : undefined}
                                className={inputClass}
                            >
                                {locales.map((locale) => (
                                    <option
                                        key={locale}
                                        value={locale}
                                        lang={locale}
                                    >
                                        {t(`account.profile.locales.${locale}`)}
                                    </option>
                                ))}
                            </select>
                            {errors.locale ? (
                                <p role="alert" className={fieldErrorClass}>
                                    {errors.locale}
                                </p>
                            ) : null}
                        </div>
                        <div className="flex items-start gap-3">
                            <input
                                id="profile-rankings"
                                name="show_in_rankings"
                                type="checkbox"
                                value="1"
                                defaultChecked={profile.showInRankings}
                                aria-describedby="profile-rankings-hint"
                                className="mt-0.5 size-5 flex-none accent-primary"
                            />
                            <div className="grid gap-1">
                                <label
                                    htmlFor="profile-rankings"
                                    className="text-sm font-semibold"
                                >
                                    {t('account.profile.rankings')}
                                </label>
                                <p
                                    id="profile-rankings-hint"
                                    className="text-[13px] text-muted"
                                >
                                    {t('account.profile.rankingsHint')}
                                </p>
                            </div>
                        </div>
                        <button
                            type="submit"
                            disabled={processing}
                            className={`${primaryButtonClass} justify-self-start`}
                        >
                            {t('account.profile.save')}
                        </button>
                    </>
                )}
            </Form>
        </section>
    );
}
