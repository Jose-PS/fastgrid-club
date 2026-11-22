import { Head, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import {
    alertClass,
    secondaryButtonClass,
    warningClass,
} from '@/components/account-styles';
import { DangerZone } from '@/components/danger-zone';
import { PasskeysSection } from '@/components/passkeys-section';
import type { AccountPasskey } from '@/components/passkeys-section';
import { ProfileSection } from '@/components/profile-section';
import type { AccountProfile } from '@/components/profile-section';
import { SignInMethodsSection } from '@/components/sign-in-methods-section';
import type { AccountMethods } from '@/components/sign-in-methods-section';
import { useT } from '@/hooks/use-t';
import { link as steamLink } from '@/routes/account/identities/steam';

type AccountFlash = {
    steamError?: string;
    googleError?: string;
    accountError?: string;
};

export default function AccountShow({
    passkeys,
    methods,
    profile,
    locales,
    confirmedUntil,
}: {
    passkeys: AccountPasskey[];
    methods: AccountMethods;
    profile: AccountProfile;
    locales: string[];
    confirmedUntil: string | null;
}) {
    const t = useT();
    const flash = usePage().flash as AccountFlash;
    const errors = [flash.steamError, flash.googleError, flash.accountError];

    return (
        <>
            <Head title={t('account.title')} />
            <div className="grid max-w-[720px] gap-7">
                <div>
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('account.title')}
                    </h1>
                    <p className="mt-2 text-muted">{t('account.sub')}</p>
                </div>
                {errors.map((message) =>
                    message ? (
                        <p key={message} role="alert" className={alertClass}>
                            {message}
                        </p>
                    ) : null,
                )}
                {methods.steam === null && methods.google === null ? (
                    <div className={warningClass}>
                        <p className="flex items-center gap-2">
                            <TriangleAlert
                                className="size-[18px] flex-none"
                                aria-hidden="true"
                            />
                            {t('account.methods.steamMissing')}
                        </p>
                        <a
                            href={steamLink().url}
                            className={secondaryButtonClass}
                        >
                            {t('account.methods.link')}
                        </a>
                    </div>
                ) : null}
                <SignInMethodsSection
                    methods={methods}
                    confirmedUntil={confirmedUntil}
                />
                <PasskeysSection passkeys={passkeys} />
                <ProfileSection profile={profile} locales={locales} />
                <DangerZone />
            </div>
        </>
    );
}
